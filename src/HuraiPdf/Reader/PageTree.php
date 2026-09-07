<?php

declare(strict_types=1);

namespace HuraiPdf\Reader;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\PdfObject;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class PageTree extends Subsystem
{
    /**
     * @param int[] $selected
     * @param array<int, PdfObject> $objects
     * @param array{offsets:array<int,array{offset:int,generation:int,next:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool} $index
     * @param InputSource $handle
     * @param string[] $warnings
     * @param array<int, bool> $seen
     */
    public function walkPageTreeFromIndex(
        int $objectId,
        int $fromPage,
        ?int $toPage,
        int &$ordinal,
        int &$pagesFound,
        array &$selected,
        array &$objects,
        array $index,
        InputSource $handle,
        array &$warnings,
        int $depth = 0,
        array &$seen = []
    ): void {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Page tree exceeds maxRecursionDepth.');
        }
        $this->session->budget->guardDeadline();
        if ($toPage !== null && $ordinal >= $toPage) {
            return;
        }
        $this->visitNode($objectId, $seen);
        if (!$this->session->reader->loadObjectFromIndex($objectId, $objects, $index, $handle, $warnings)) {
            return;
        }
        $entries = $this->session->syntax->dictionaryEntries($objects[$objectId]->body);
        $type = $this->session->encoding->decodePdfNameEscapes($entries['Type'] ?? '');
        if ($type === '/Page') {
            $ordinal++;
            $pagesFound++;
            if ($ordinal >= $fromPage && ($toPage === null || $ordinal <= $toPage)) {
                $selected[] = $objectId;
                if (count($selected) > $this->options->maxPages) {
                    throw PdfParseException::resourceLimitExceeded('Parsed page count exceeds maxPages.');
                }
            }
            return;
        }
        if ($type !== '/Pages') { return; }
        foreach ($this->childIds($entries, $objects) as $childId) {
            if ($this->session->reader->loadObjectFromIndex($childId, $objects, $index, $handle, $warnings)) {
                $childEntries = $this->session->syntax->dictionaryEntries($objects[$childId]->body);
                if ($this->session->encoding->decodePdfNameEscapes($childEntries['Type'] ?? '') === '/Pages'
                    && preg_match('/^\d+$/', $childEntries['Count'] ?? '') === 1) {
                    $subtreeCount = (int) $childEntries['Count'];
                    if ($subtreeCount > 0 && $subtreeCount < $fromPage - $ordinal) {
                        $this->visitNode($childId, $seen);
                        $ordinal += $subtreeCount;
                        $pagesFound += $subtreeCount;
                        continue;
                    }
                }
            }
            $this->walkPageTreeFromIndex(
                $childId,
                $fromPage,
                $toPage,
                $ordinal,
                $pagesFound,
                $selected,
                $objects,
                $index,
                $handle,
                $warnings,
                $depth + 1,
                $seen
            );
            if ($toPage !== null && $ordinal >= $toPage) {
                break;
            }
        }
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return int[]
     */
    public function resolvePageObjectIds(array &$objects, ?int $toPage = null): array
    {
        $limit = $toPage ?? PHP_INT_MAX;
        $catalogObject = null;
        foreach ($objects as $object) {
            if ($this->objectType($object) === '/Catalog') {
                $catalogObject = $object;
                break;
            }
        }

        if ($catalogObject !== null && preg_match('/^(\d+)\s+\d+\s+R$/', $this->session->syntax->dictionaryEntries($catalogObject->body)['Pages'] ?? '', $m) === 1) {
            $rootPagesId = (int) $m[1];
            $seen = [];
            $ordered = $this->walkPageTree($rootPagesId, $objects, $seen, $limit);
            if ($ordered !== []) {
                return $ordered;
            }
        }

        $pages = [];
        foreach ($objects as $object) {
            if ($this->objectType($object) === '/Page') {
                $pages[] = ['id' => $object->id, 'offset' => $object->offset];
            }
        }

        usort(
            $pages,
            static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']
        );

        if (count($pages) > $limit) {
            $pages = array_slice($pages, 0, $limit);
        }

        return array_map(static fn(array $item): int => $item['id'], $pages);
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $seen
     * @return int[]
     */
    private function walkPageTree(
        int $objectId,
        array &$objects,
        array &$seen,
        int $limit = PHP_INT_MAX,
        int $depth = 0
    ): array
    {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Page tree exceeds maxRecursionDepth.');
        }
        $this->session->budget->guardDeadline();
        $this->visitNode($objectId, $seen);
        if (!isset($objects[$objectId])) { return []; }
        $entries = $this->session->syntax->dictionaryEntries($objects[$objectId]->body);
        $type = $this->session->encoding->decodePdfNameEscapes($entries['Type'] ?? '');
        if ($type === '/Page') {
            return [$objectId];
        }

        if ($type !== '/Pages') { return []; }
        $ids = [];
        foreach ($this->childIds($entries, $objects) as $kidId) {
            if (count($ids) >= $limit) {
                break;
            }
            foreach ($this->walkPageTree($kidId, $objects, $seen, $limit, $depth + 1) as $pageId) {
                $ids[] = $pageId;
                if (count($ids) >= $limit) {
                    break 2;
                }
            }
        }

        return $ids;
    }

    /** Reject cycles and shared branches before they multiply traversal work. */
    private function visitNode(int $objectId, array &$seen): void
    {
        if (isset($seen[$objectId])) {
            throw PdfParseException::resourceLimitExceeded('Page tree contains a repeated object reference.');
        }
        $this->session->budget->assertObjectBudget(count($seen) + 1);
        $seen[$objectId] = true;
    }

    private function objectType(PdfObject $object): string
    {
        return $this->session->encoding->decodePdfNameEscapes(
            $this->session->syntax->dictionaryEntries($object->body)['Type'] ?? ''
        );
    }

    /** @return \Generator<int> */
    private function childIds(array $entries, array &$objects): \Generator
    {
        $kids = $entries['Kids'] ?? '';
        if (preg_match('/^(\d+)\s+\d+\s+R$/', $kids, $ref) === 1) {
            $kids = $this->session->resources->resolveIndirectObjectToken((int) $ref[1], $objects, []);
        }
        yield from $this->session->syntax->arrayReferenceIds($kids);
    }
}
