<?php

declare(strict_types=1);

namespace HuraiPdf\Content;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\PdfObject;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class ResourceResolver extends Subsystem
{
    /** @var array<int, true> */
    private array $resolvingValues = [];

    /**
     * @param array<int, PdfObject> $objects
     * @return array<string, int>
     */
    public function buildPageXObjectMap(int $pageObjectId, string $pageBody, array &$objects): array
    {
        $xObjectMap = [];
        $resourceBodies = $this->resolveResourceDictionaryBodies($pageObjectId, $pageBody, $objects);
        foreach ($resourceBodies as $resourceBody) {
            foreach ($this->extractXObjectMapFromResourceDictionary($resourceBody, $objects) as $name => $objectId) {
                $xObjectMap[$name] = $objectId;
            }
        }

        return $xObjectMap;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return int[]
     */
    public function resolvePageContentObjectIds(string $pageBody, array &$objects): array
    {
        $ids = [];
        $visited = [];
        $referenceCount = 0;

        $contents = $this->session->syntax->dictionaryEntries($pageBody)['Contents'] ?? '';
        if (str_starts_with($contents, '[')) {
            foreach ($this->session->syntax->arrayReferenceIds($contents) as $refId) {
                foreach ($this->resolveContentReferenceObjectIds($refId, $objects, $visited, $referenceCount) as $streamId) {
                    $ids[] = $streamId;
                }
            }
        } elseif (preg_match('/^(\d+)\s+\d+\s+R$/', $contents, $ref) === 1) {
            $ids = $this->resolveContentReferenceObjectIds((int) $ref[1], $objects, $visited, $referenceCount);
        }

        return $ids;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     * @return int[]
     */
    private function resolveContentReferenceObjectIds(
        int $objectId,
        array &$objects,
        array $visited,
        int &$referenceCount,
        int $depth = 0
    ): array
    {
        $this->session->budget->guardDeadline();
        if (++$referenceCount > $this->options->maxArrayElements) {
            throw PdfParseException::resourceLimitExceeded('Page content references exceed maxArrayElements.');
        }
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Content reference nesting exceeds maxRecursionDepth.');
        }
        if (isset($visited[$objectId])) {
            return [];
        }
        $visited[$objectId] = true;

        if (!$this->session->reader->ensureObject($objectId, $objects)) {
            return [];
        }

        $body = trim($objects[$objectId]->body);
        if ($body === '') {
            return [];
        }

        if (strpos($body, 'stream') !== false && strpos($body, 'endstream') !== false) {
            return [$objectId];
        }

        if (str_starts_with($body, '[')) {
            $ids = [];
            foreach ($this->session->syntax->arrayReferenceIds($body) as $refId) {
                foreach ($this->resolveContentReferenceObjectIds($refId, $objects, $visited, $referenceCount, $depth + 1) as $streamId) {
                    $ids[] = $streamId;
                }
            }
            return $ids;
        }

        if (preg_match('/^(\d+)\s+\d+\s+R$/', $body, $singleRefMatch) === 1) {
            return $this->resolveContentReferenceObjectIds((int) $singleRefMatch[1], $objects, $visited, $referenceCount, $depth + 1);
        }

        return [];
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     * @return array<string, array{map: array<string, string>, max_code_bytes: int}>
     */
    public function buildPageFontMaps(int $pageObjectId, string $pageBody, array &$objects, array &$warnings): array
    {
        $resourceBodies = $this->resolveResourceDictionaryBodies($pageObjectId, $pageBody, $objects);
        return $this->buildFontMapsFromResourceBodies($resourceBodies, $objects, $warnings);
    }

    /**
     * @param string[] $resourceBodies
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     * @return array<string, array{map:array<string,string>,max_code_bytes:int,encoding_name:string,differences:array<int,string>,is_multibyte:bool,has_tounicode:bool}>
     */
    public function buildFontMapsFromResourceBodies(array $resourceBodies, array &$objects, array &$warnings): array
    {
        if ($resourceBodies === []) {
            return [];
        }
        $cacheKey = sha1(implode("\x00", $resourceBodies));
        if (isset($this->context->resourceFontMapsCache[$cacheKey])) {
            return $this->context->resourceFontMapsCache[$cacheKey];
        }

        $fontMaps = [];
        foreach ($resourceBodies as $resourceBody) {
            $fontDictionary = $this->resolveValue($this->session->syntax->dictionaryEntries($resourceBody)['Font'] ?? '', $objects);
            foreach ($this->session->syntax->dictionaryEntries($fontDictionary) as $resourceName => $value) {
                if (preg_match('/^(\d+)\s+\d+\s+R$/', $value, $ref) !== 1) { continue; }
                $fontObjectId = (int) $ref[1];
                if (!$this->session->reader->ensureObject($fontObjectId, $objects)) { continue; }
                $fontMaps[$resourceName] = $this->buildFontMapData($fontObjectId, $objects[$fontObjectId]->body, $objects, $warnings);
            }
        }

        return $this->context->resourceFontMapsCache[$cacheKey] = $fontMaps;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     * @return array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * }
     */
    private function buildFontMapData(int $fontObjectId, string $fontBody, array &$objects, array &$warnings): array
    {
        $cache = &$this->context->fontMapCache;
        if (array_key_exists($fontObjectId, $cache)) {
            return $cache[$fontObjectId];
        }

        $encodingData = $this->session->encoding->parseFontEncodingData($fontObjectId, $fontBody, $objects);
        $mapData = [
            'map' => [],
            'max_code_bytes' => $encodingData['is_multibyte'] ? 2 : 1,
            'encoding_name' => $encodingData['encoding_name'],
            'differences' => $encodingData['differences'],
            'is_multibyte' => $encodingData['is_multibyte'],
            'has_tounicode' => false,
        ];

        if (preg_match('/^(\d+)\s+\d+\s+R$/', $this->session->syntax->dictionaryEntries($fontBody)['ToUnicode'] ?? '', $toUnicodeMatch) !== 1) {
            return $cache[$fontObjectId] = $mapData;
        }

        $toUnicodeObjectId = (int) $toUnicodeMatch[1];
        if (!$this->session->reader->ensureObject($toUnicodeObjectId, $objects)) {
            return $cache[$fontObjectId] = $mapData;
        }

        $streamInfo = $this->session->reader->extractStreamInfoFromObjectBody($objects[$toUnicodeObjectId]->body, $objects);
        if ($streamInfo === null) {
            return $cache[$fontObjectId] = $mapData;
        }

        $decodedCMap = $this->session->decoder->decodeStream(
            $streamInfo['dictionary'],
            $streamInfo['stream'],
            $warnings,
            $toUnicodeObjectId,
            objects: $objects
        );

        if ($decodedCMap === '') {
            return $cache[$fontObjectId] = $mapData;
        }

        // Guard against malformed PDFs embedding oversized CMap data (ReDoS / memory protection).
        // Legitimate ToUnicode CMap tables are never larger than ~100 KB.
        if (strlen($decodedCMap) > $this->options->maxCMapSize) {
            $this->session->budget->addWarning($warnings, 'ToUnicode CMap on object ' . $fontObjectId . ' exceeds configured size — truncated for safety.');
            $decodedCMap = substr($decodedCMap, 0, $this->options->maxCMapSize);
        }

        $toUnicodeMapData = $this->session->cmap->parseToUnicodeCMap($decodedCMap);
        if ($toUnicodeMapData['map'] !== []) {
            $mapData['map'] = $toUnicodeMapData['map'];
            $mapData['max_code_bytes'] = max($mapData['max_code_bytes'], $toUnicodeMapData['max_code_bytes']);
            $mapData['has_tounicode'] = true;
        }

        return $cache[$fontObjectId] = $mapData;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return string[]
     */
    private function resolveResourceDictionaryBodies(int $pageObjectId, string $pageBody, array &$objects): array
    {
        if (isset($this->context->resourceBodiesCache[$pageObjectId])) {
            return $this->context->resourceBodiesCache[$pageObjectId];
        }

        $bodies = [];
        foreach ($this->extractResourceDictionaryBodiesFromObjectBody($pageBody, $objects) as $body) {
            $bodies[] = $body;
        }

        if ($bodies !== []) {
            return $this->context->resourceBodiesCache[$pageObjectId] = $bodies;
        }

        $currentPageId = $pageObjectId;
        $visited = [];
        while (!isset($visited[$currentPageId]) && $this->session->reader->ensureObject($currentPageId, $objects)) {
            $visited[$currentPageId] = true;
            $currentBody = $objects[$currentPageId]->body;

            foreach ($this->extractResourceDictionaryBodiesFromObjectBody($currentBody, $objects) as $body) {
                $bodies[] = $body;
            }
            if ($bodies !== []) {
                break;
            }

            if (preg_match('/^(\d+)\s+\d+\s+R$/', $this->session->syntax->dictionaryEntries($currentBody)['Parent'] ?? '', $parentMatch) !== 1) {
                break;
            }

            $currentPageId = (int) $parentMatch[1];
        }

        return $this->context->resourceBodiesCache[$pageObjectId] = $bodies;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return string[]
     */
    public function extractResourceDictionaryBodiesFromObjectBody(string $objectBody, array &$objects): array
    {
        $value = $this->resolveValue($this->session->syntax->dictionaryEntries($objectBody)['Resources'] ?? '', $objects);
        return str_starts_with($value, '<<') ? [$value] : [];
    }

    public function resolveValue(string $value, array &$objects): string
    {
        if (preg_match('/^(\d+)\s+\d+\s+R$/', $value, $ref) !== 1) { return $value; }
        $id = (int) $ref[1];
        if (isset($this->resolvingValues[$id]) || count($this->resolvingValues) >= $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Indirect dictionary values exceed the resolution depth limit.');
        }
        $this->resolvingValues[$id] = true;
        try { return $this->resolveIndirectObjectToken($id, $objects, []); }
        finally { unset($this->resolvingValues[$id]); }
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     */
    public function resolveIndirectObjectToken(int $objectId, array &$objects, array $visited, int $depth = 0): string
    {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Indirect object nesting exceeds maxRecursionDepth.');
        }
        if (isset($visited[$objectId]) || !$this->session->reader->ensureObject($objectId, $objects)) {
            return '';
        }

        $visited[$objectId] = true;
        $body = trim($objects[$objectId]->body);
        if ($body === '') {
            return '';
        }

        if (preg_match('/^(\d+)\s+\d+\s+R$/', $body, $refMatch) === 1) {
            return $this->resolveIndirectObjectToken((int) $refMatch[1], $objects, $visited, $depth + 1);
        }

        return $body;
    }

    /**
     * @return array<string, int>
     */
    public function extractXObjectMapFromResourceDictionary(string $resourceBody, array &$objects): array
    {
        $cacheKey = sha1($resourceBody);
        if (isset($this->context->resourceXObjectMapsCache[$cacheKey])) {
            return $this->context->resourceXObjectMapsCache[$cacheKey];
        }

        $map = [];
        $dictionary = $this->resolveValue($this->session->syntax->dictionaryEntries($resourceBody)['XObject'] ?? '', $objects);
        foreach ($this->session->syntax->dictionaryEntries($dictionary) as $name => $value) {
            if (preg_match('/^(\d+)\s+\d+\s+R$/', $value, $ref) === 1) {
                $map[$name] = (int) $ref[1];
            }
        }

        return $this->context->resourceXObjectMapsCache[$cacheKey] = $map;
    }
}
