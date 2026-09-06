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
        int $depth = 0
    ): void {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Page tree exceeds maxRecursionDepth.');
        }
        $this->session->budget->guardDeadline();
        if ($toPage !== null && $ordinal >= $toPage) {
            return;
        }
        if (!$this->session->reader->loadObjectFromIndex($objectId, $objects, $index, $handle, $warnings)) {
            return;
        }
        $body = $objects[$objectId]->body;
        if (preg_match('/\/Type\s*\/Page\b/', $body) === 1 && preg_match('/\/Type\s*\/Pages\b/', $body) !== 1) {
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
        if (
            preg_match('/\/Type\s*\/Pages\b/', $body) !== 1 ||
            preg_match('/\/Kids\s*\[(.*?)\]/s', $body, $kidsMatch) !== 1
        ) {
            return;
        }
        preg_match_all('/(\d+)\s+\d+\s+R/', $kidsMatch[1], $references);
        foreach ($references[1] as $childId) {
            $childId = (int) $childId;
            if ($this->session->reader->loadObjectFromIndex($childId, $objects, $index, $handle, $warnings)) {
                $childBody = $objects[$childId]->body;
                if (
                    preg_match('/\/Type\s*\/Pages\b/', $childBody) === 1 &&
                    preg_match('/\/Count\s+(\d+)\b/', $childBody, $countMatch) === 1
                ) {
                    $subtreeCount = (int) $countMatch[1];
                    if ($subtreeCount > 0 && $ordinal + $subtreeCount < $fromPage) {
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
                $depth + 1
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
            if (str_contains($object->body, '/Catalog') && preg_match('/\/Type\s*\/Catalog\b/', $object->body)) {
                $catalogObject = $object;
                break;
            }
        }

        if ($catalogObject !== null && preg_match('/\/Pages\s+(\d+)\s+\d+\s+R/', $catalogObject->body, $m) === 1) {
            $rootPagesId = (int) $m[1];
            $seen = [];
            $ordered = $this->walkPageTree($rootPagesId, $objects, $seen, $limit);
            if ($ordered !== []) {
                return $ordered;
            }
        }

        $pages = [];
        foreach ($objects as $object) {
            // str_contains pre-check avoids regex on objects that clearly lack /Page
            if (
                str_contains($object->body, '/Page') &&
                preg_match('/\/Type\s*\/Page\b(?!s)/', $object->body)
            ) {
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
        if (isset($seen[$objectId]) || !isset($objects[$objectId])) {
            return [];
        }

        $seen[$objectId] = true;
        $body = $objects[$objectId]->body;

        if (preg_match('/\/Type\s*\/Page\b/', $body) && !preg_match('/\/Type\s*\/Pages\b/', $body)) {
            return [$objectId];
        }

        if (!preg_match('/\/Type\s*\/Pages\b/', $body)) {
            return [];
        }

        if (!preg_match('/\/Kids\s*\[(.*?)\]/s', $body, $kidsMatch)) {
            return [];
        }

        $ids = [];
        preg_match_all('/(\d+)\s+\d+\s+R/', $kidsMatch[1], $refMatches);
        foreach ($refMatches[1] as $kidIdRaw) {
            if (count($ids) >= $limit) {
                break;
            }
            $kidId = (int) $kidIdRaw;
            foreach ($this->walkPageTree($kidId, $objects, $seen, $limit, $depth + 1) as $pageId) {
                $ids[] = $pageId;
                if (count($ids) >= $limit) {
                    break 2;
                }
            }
        }

        return $ids;
    }
}
