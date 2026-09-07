<?php

declare(strict_types=1);

namespace HuraiPdf\Content;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\PdfObject;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class ResourceResolver extends Subsystem
{
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
            $fontDictStrings = [];

            if (preg_match('/\/Font\s*<<((?:[^>]|>(?!>))*+)>>/s', $resourceBody, $fontDictMatch) === 1) {
                $fontDictStrings[] = $fontDictMatch[1];
            }

            if (preg_match('/\/Font\s+(\d+)\s+\d+\s+R/', $resourceBody, $fontRefMatch) === 1) {
                $resolvedBody = $this->resolveIndirectObjectBody((int) $fontRefMatch[1], $objects, []);
                if ($resolvedBody !== '') {
                    $fontDictStrings[] = $resolvedBody;
                }
            }

            foreach ($fontDictStrings as $fontDictContent) {
                preg_match_all('/\/([^\s\/<>\[\]\(\)\{\}%]+)\s+(\d+)\s+\d+\s+R/', $fontDictContent, $fontRefs, PREG_SET_ORDER);
                foreach ($fontRefs as $fontRef) {
                    $resourceName = $this->session->encoding->decodePdfNameEscapes($fontRef[1]);
                    $fontObjectId = (int) $fontRef[2];

                    if (!$this->session->reader->ensureObject($fontObjectId, $objects)) {
                        continue;
                    }

                    $fontBody = $objects[$fontObjectId]->body;
                    $mapData = $this->buildFontMapData($fontObjectId, $fontBody, $objects, $warnings);
                    $fontMaps[$resourceName] = $mapData;
                }
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

        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fontBody, $toUnicodeMatch) !== 1) {
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
            $toUnicodeObjectId
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

            if (preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $currentBody, $parentMatch) !== 1) {
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
        $bodies = [];

        $inlineResourceDictionary = $this->extractInlineDictionaryForKey($objectBody, 'Resources');
        if ($inlineResourceDictionary !== '') {
            $bodies[] = $inlineResourceDictionary;
        }

        if (preg_match('/\/Resources\s+(\d+)\s+\d+\s+R/', $objectBody, $refMatch) === 1) {
            $resourceObjectBody = $this->resolveResourceObjectBody((int) $refMatch[1], $objects, []);
            if ($resourceObjectBody !== '') {
                $bodies[] = $resourceObjectBody;
            }
        }

        return $bodies;
    }

    private function extractInlineDictionaryForKey(string $body, string $key): string
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '\s*<</s', $body, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $tokenOffset = (int) $match[0][1];
        $dictionaryStart = strpos($body, '<<', $tokenOffset);
        if ($dictionaryStart === false) {
            return '';
        }

        $dictionary = $this->session->syntax->extractFirstDictionary(substr($body, $dictionaryStart));
        if ($dictionary === null) {
            return '';
        }

        return $dictionary;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     */
    private function resolveResourceObjectBody(int $objectId, array &$objects, array $visited): string
    {
        return $this->resolveIndirectObjectBody($objectId, $objects, $visited);
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     */
    private function resolveIndirectObjectBody(int $objectId, array &$objects, array $visited, int $depth = 0): string
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

        if (
            str_starts_with($body, '<<') &&
            str_contains($body, '>>')
        ) {
            return $body;
        }

        if (preg_match('/^(\d+)\s+\d+\s+R$/', $body, $refMatch) === 1) {
            return $this->resolveIndirectObjectBody((int) $refMatch[1], $objects, $visited, $depth + 1);
        }

        return '';
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
        $xObjectDictionaries = [];

        if (preg_match('/\/XObject\s*<<((?:[^>]|>(?!>))*+)>>/s', $resourceBody, $xObjectInlineMatch) === 1) {
            $xObjectDictionaries[] = '<<' . $xObjectInlineMatch[1] . '>>';
        }

        if (preg_match('/\/XObject\s+(\d+)\s+\d+\s+R/', $resourceBody, $xObjectRefMatch) === 1) {
            $xObjectBody = $this->resolveIndirectObjectBody((int) $xObjectRefMatch[1], $objects, []);
            if ($xObjectBody !== '') {
                $xObjectDictionaries[] = $xObjectBody;
            }
        }

        foreach ($xObjectDictionaries as $xObjectDictionary) {
            preg_match_all('/\/([^\s\/<>\[\]\(\)\{\}%]+)\s+(\d+)\s+\d+\s+R/', $xObjectDictionary, $refs, PREG_SET_ORDER);
            foreach ($refs as $ref) {
                $map[$this->session->encoding->decodePdfNameEscapes($ref[1])] = (int) $ref[2];
            }
        }

        return $this->context->resourceXObjectMapsCache[$cacheKey] = $map;
    }
}
