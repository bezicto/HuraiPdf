<?php

declare(strict_types=1);

namespace HuraiPdf;

use HuraiPdf\Exception\PdfParseException;

final class Parser
{
    /** @var array<int, array{map:array<string,string>,max_code_bytes:int,encoding_name:string,differences:array<int,string>,is_multibyte:bool,has_tounicode:bool}> */
    private array $fontMapCache = [];

    private readonly ParserOptions $options;

    public function __construct(ParserOptions $options = new ParserOptions())
    {
        $this->options = $options;
    }

    public function parseFile(string $filePath, int $fromPage = 1, ?int $toPage = null): Document
    {
        $this->fontMapCache = [];

        if ($fromPage < 1) {
            throw PdfParseException::invalidPageRange('fromPage must be 1 or greater.');
        }
        if ($toPage !== null && $toPage < $fromPage) {
            throw PdfParseException::invalidPageRange('toPage must be greater than or equal to fromPage.');
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw PdfParseException::fileNotReadable($filePath);
        }

        $fileSize = filesize($filePath);
        if ($fileSize !== false && $fileSize > $this->options->streamingThreshold) {
            return $this->parseFileStreaming($filePath, $fromPage, $toPage);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw PdfParseException::fileReadFailure();
        }

        return $this->parseContent($content, $fromPage, $toPage);
    }

    /**
     * Memory-efficient file parsing for large PDFs: reads only the xref table and
     * the individual object bytes actually needed, avoiding a full file_get_contents.
     */
    private function parseFileStreaming(string $filePath, int $fromPage = 1, ?int $toPage = null): Document
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw PdfParseException::fileReadFailure();
        }

        try {
            $stat = fstat($handle);
            $fileSize = $stat !== false ? (int) $stat['size'] : 0;

            // Step 1: read the tail to locate startxref
            $tailSize = min(1024, $fileSize);
            fseek($handle, -$tailSize, SEEK_END);
            $tail = fread($handle, $tailSize);
            if ($tail === false) {
                throw PdfParseException::fileReadFailure();
            }

            $pos = strrpos($tail, 'startxref');
            if ($pos === false || !preg_match('/\s+(\d+)/', substr($tail, $pos + 9, 64), $m)) {
                // No valid startxref — fall back to full read
                fseek($handle, 0);
                $content = stream_get_contents($handle);
                if ($content === false) {
                    throw PdfParseException::fileReadFailure();
                }
                return $this->parseContent($content, $fromPage, $toPage);
            }
            $xrefOffset = (int) $m[1];

            // Step 2: build object offset map from xref (read only what's needed)
            // Read a generous region around xref for both traditional and stream xref
            $xrefReadSize = min($fileSize - $xrefOffset, 65536);
            fseek($handle, $xrefOffset);
            $xrefRegion = fread($handle, $xrefReadSize);
            if ($xrefRegion === false) {
                throw PdfParseException::fileReadFailure();
            }

            // Append a minimal startxref footer so parseXRefOffsets can locate the
            // xref within $xrefRegion. Pass $xrefOffset as the base so all internal
            // offset arithmetic stays relative to the region buffer — no NUL prefix needed.
            $regionWithFooter = $xrefRegion . "\nstartxref\n" . $xrefOffset . "\n%%EOF";

            $objectOffsets = $this->parseXRefOffsets($regionWithFooter, $xrefOffset);

            if ($objectOffsets === []) {
                // xref could not be parsed — fall back to full read
                fseek($handle, 0);
                $content = stream_get_contents($handle);
                if ($content === false) {
                    throw PdfParseException::fileReadFailure();
                }
                return $this->parseContent($content, $fromPage, $toPage);
            }

            // Step 3: read each object body individually via fseek
            $objects = [];
            // Estimate max object size; we read up to 256 KB per object then expand
            $chunkSize = $this->options->objectChunkSize;
            foreach ($objectOffsets as $id => $byteOffset) {
                if ($id <= 0 || $byteOffset <= 0 || $byteOffset >= $fileSize) {
                    continue;
                }

                fseek($handle, $byteOffset);
                $chunk = fread($handle, min($chunkSize, $fileSize - $byteOffset));
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                if (!preg_match('/^\s*(\d+)\s+(\d+)\s+obj\b/', $chunk, $headerMatch)) {
                    continue;
                }
                $generation = (int) $headerMatch[2];
                $bodyStart = strlen($headerMatch[0]);

                // Expand chunk if endobj is not found within the first read
                while (strpos($chunk, 'endobj', $bodyStart) === false) {
                    if (strlen($chunk) > 50 * 1024 * 1024) {
                        break; // 50 MB single-object cap: corrupt/crafted PDF guard
                    }
                    $extra = fread($handle, $chunkSize);
                    if ($extra === false || $extra === '') {
                        break;
                    }
                    $chunk .= $extra;
                }

                $endObjOffset = $this->locateEndObjOffset($chunk, $bodyStart);
                if ($endObjOffset === null || $endObjOffset < $bodyStart) {
                    continue;
                }

                $body = substr($chunk, $bodyStart, $endObjOffset - $bodyStart);
                if ($body === false) {
                    continue;
                }

                $objects[$id] = new PdfObject($id, $generation, $body, $byteOffset, false);
            }

            if ($objects === []) {
                throw PdfParseException::noObjectsFound();
            }

            // Step 4: run the normal pipeline from here (same as parseContent)
            $warnings = [];
            // Read PDF version from the very beginning of the file
            fseek($handle, 0);
            $header = fread($handle, 16);
            $pdfVersion = ($header !== false && preg_match('/^%PDF-([0-9.]+)/', $header, $vMatch))
                ? $vMatch[1]
                : 'unknown';

            $this->expandObjectStreams($objects, $warnings);

            $pageObjectIds = $this->resolvePageObjectIds($objects, $toPage);
            if ($pageObjectIds === []) {
                throw PdfParseException::noPagesFound();
            }

            $pageObjectIds = array_slice(
                $pageObjectIds,
                $fromPage - 1,
                $toPage !== null ? $toPage - $fromPage + 1 : null
            );

            $pages = [];
            foreach ($pageObjectIds as $index => $pageObjectId) {
                $text = $this->extractPageText($pageObjectId, $objects, $warnings);
                $pages[] = new Page($fromPage + $index, $pageObjectId, $this->normalizeText($text));
            }

            // Check for encryption marker in the xref region we already have
            $encrypted = str_contains($xrefRegion, '/Encrypt');
            if ($encrypted) {
                $warnings[] = 'Encrypted PDF detected. Extraction quality may be limited.';
            }

            return new Document($pages, $pdfVersion, $encrypted, $warnings);
        } finally {
            fclose($handle);
        }
    }



    public function parseContent(string $content, int $fromPage = 1, ?int $toPage = null): Document
    {
        if ($fromPage < 1) {
            throw PdfParseException::invalidPageRange('fromPage must be 1 or greater.');
        }
        if ($toPage !== null && $toPage < $fromPage) {
            throw PdfParseException::invalidPageRange('toPage must be greater than or equal to fromPage.');
        }

        if (!str_starts_with($content, '%PDF-')) {
            throw PdfParseException::invalidHeader();
        }

        $warnings = [];
        $pdfVersion = $this->extractPdfVersion($content);
        $objects = $this->collectIndirectObjects($content);

        if ($objects === []) {
            throw PdfParseException::noObjectsFound();
        }

        $this->expandObjectStreams($objects, $warnings);

        $pageObjectIds = $this->resolvePageObjectIds($objects, $toPage);
        if ($pageObjectIds === []) {
            throw PdfParseException::noPagesFound();
        }

        $pageObjectIds = array_slice($pageObjectIds, $fromPage - 1, $toPage !== null ? $toPage - $fromPage + 1 : null);

        $pages = [];
        foreach ($pageObjectIds as $index => $pageObjectId) {
            $text = $this->extractPageText($pageObjectId, $objects, $warnings);
            $pages[] = new Page($fromPage + $index, $pageObjectId, $this->normalizeText($text));
        }

        // Scan only the trailer region (last 4 KB) instead of the full content string
        $trailerTail = substr($content, max(0, strlen($content) - 4096));
        $encrypted = str_contains($trailerTail, '/Encrypt');
        if ($encrypted) {
            $warnings[] = 'Encrypted PDF detected. Extraction quality may be limited.';
        }

        return new Document($pages, $pdfVersion, $encrypted, $warnings);
    }

    private function extractPdfVersion(string $content): string
    {
        if (preg_match('/^%PDF-([0-9.]+)/', $content, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * @return array<int, PdfObject>
     */
    private function collectIndirectObjects(string $content): array
    {
        $objects = [];

        // Fast path: use xref table to jump directly to each object
        $xrefOffsets = $this->parseXRefOffsets($content);
        if ($xrefOffsets !== []) {
            $contentLength = strlen($content);
            foreach ($xrefOffsets as $id => $byteOffset) {
                if ($id <= 0 || $byteOffset <= 0 || $byteOffset >= $contentLength) {
                    continue;
                }
                $slice = substr($content, $byteOffset, 64);
                if (!preg_match('/^\s*(\d+)\s+(\d+)\s+obj\b/', $slice, $m)) {
                    continue;
                }
                $generation = (int) $m[2];
                $bodyStart = $byteOffset + strlen($m[0]);
                $endObjOffset = $this->locateEndObjOffset($content, $bodyStart);
                if ($endObjOffset === null || $endObjOffset < $bodyStart) {
                    continue;
                }
                $body = substr($content, $bodyStart, $endObjOffset - $bodyStart);
                if ($body === false) {
                    continue;
                }
                $objects[$id] = new PdfObject($id, $generation, $body, $byteOffset, false);
            }
            if ($objects !== []) {
                return $objects;
            }
        }

        // Fallback: full content scan
        $objects = [];
        if (
            preg_match_all(
                '/(?:^|[\r\n])(\d+)\s+(\d+)\s+obj\b/',
                $content,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            ) !== 1
            && $matches === []
        ) {
            return [];
        }

        foreach ($matches as $match) {
            $id = (int) $match[1][0];
            $generation = (int) $match[2][0];
            if ($id <= 0) {
                continue;
            }

            $objectStart = (int) $match[1][1];
            $bodyStart = (int) $match[0][1] + strlen((string) $match[0][0]);
            $endObjOffset = $this->locateEndObjOffset($content, $bodyStart);
            if ($endObjOffset === null || $endObjOffset < $bodyStart) {
                continue;
            }

            $body = substr($content, $bodyStart, $endObjOffset - $bodyStart);
            if ($body === false) {
                continue;
            }

            if (!isset($objects[$id]) || $objects[$id]->offset <= $objectStart) {
                $objects[$id] = new PdfObject($id, $generation, $body, $objectStart, false);
            }
        }

        return $objects;
    }

    /**
     * Parse cross-reference table/stream and return [objectId => byteOffset].
     *
     * @param  int $baseOffset  Absolute file offset of byte 0 of $content (0 for
     *                          full-file content; $xrefOffset for the streaming path).
     * @return array<int, int>
     */
    private function parseXRefOffsets(string $content, int $baseOffset = 0): array
    {
        $contentLength = strlen($content);
        $searchOffset = -min(1024, $contentLength);
        $pos = strrpos($content, 'startxref', $searchOffset);
        if ($pos === false) {
            return [];
        }

        $afterStartXref = substr($content, $pos + 9, 64);
        if (!preg_match('/\s+(\d+)/', $afterStartXref, $m)) {
            return [];
        }

        $xrefAbsolute = (int) $m[1];
        // Convert the absolute file offset to a position within this buffer
        $xrefLocalOffset = $xrefAbsolute - $baseOffset;
        if ($xrefLocalOffset < 0 || $xrefLocalOffset >= $contentLength) {
            return [];
        }

        $objectOffsets = [];
        $visitedOffsets = [];
        $this->collectXRefEntries($content, $xrefLocalOffset, $objectOffsets, $visitedOffsets, $baseOffset);
        return $objectOffsets;
    }

    /**
     * @param array<int, int> $objectOffsets
     * @param array<int, bool> $visitedOffsets
     * @param int $baseOffset  Absolute file offset of byte 0 of $content.
     */
    private function collectXRefEntries(string $content, int $xrefLocalOffset, array &$objectOffsets, array &$visitedOffsets, int $baseOffset = 0): void
    {
        $contentLen = strlen($content);
        $queue = [$xrefLocalOffset];

        while ($queue !== []) {
            $currentOffset = array_shift($queue);

            if (isset($visitedOffsets[$currentOffset])) {
                continue;
            }
            $visitedOffsets[$currentOffset] = true;

            $slice = ltrim(substr($content, $currentOffset, 16));
            $prevAbsolute = str_starts_with($slice, 'xref')
                ? $this->parseTraditionalXRef($content, $currentOffset, $objectOffsets)
                : $this->parseXRefStream($content, $currentOffset, $objectOffsets);

            if ($prevAbsolute !== null && $prevAbsolute > 0) {
                $prevLocalOffset = $prevAbsolute - $baseOffset;
                if ($prevLocalOffset !== $currentOffset && $prevLocalOffset >= 0 && $prevLocalOffset < $contentLen) {
                    $queue[] = $prevLocalOffset;
                }
            }
        }
    }

    /**
     * Parse a traditional (non-stream) cross-reference table.
     * @param array<int, int> $objectOffsets
     */
    private function parseTraditionalXRef(string $content, int $startOffset, array &$objectOffsets): ?int
    {
        $length = strlen($content);
        $pos = strpos($content, 'xref', $startOffset);
        if ($pos === false) {
            return null;
        }
        $pos += 4;

        // Skip whitespace/EOL after 'xref'
        while ($pos < $length && ($content[$pos] === ' ' || $content[$pos] === "\t" || $content[$pos] === "\r" || $content[$pos] === "\n")) {
            $pos++;
        }

        // Parse subsections until 'trailer'
        while ($pos < $length) {
            if (substr($content, $pos, 7) === 'trailer') {
                break;
            }
            // Subsection header: "startId count<EOL>"
            if (!preg_match('/^(\d+)\s+(\d+)\s*[\r\n]+/', substr($content, $pos, 48), $headerMatch)) {
                break;
            }
            $startId = (int) $headerMatch[1];
            $count = (int) $headerMatch[2];
            $pos += strlen($headerMatch[0]);

            // Each in-use entry: nnnnnnnnnn ggggg n <2-byte-eol> (exactly 20 bytes)
            for ($i = 0; $i < $count; $i++) {
                if ($pos + 20 > $length) {
                    break;
                }
                $entry = substr($content, $pos, 20);
                $pos += 20;
                if ($entry[17] !== 'n') {
                    continue; // free entry
                }
                $objectId = $startId + $i;
                $byteOffset = (int) substr($entry, 0, 10);
                if (!isset($objectOffsets[$objectId])) {
                    $objectOffsets[$objectId] = $byteOffset;
                }
            }
        }

        // Extract /Prev from trailer dictionary
        if (substr($content, $pos, 7) === 'trailer') {
            $trailerSlice = substr($content, $pos, 512);
            if (preg_match('/\/Prev\s+(\d+)/', $trailerSlice, $prevMatch)) {
                return (int) $prevMatch[1];
            }
        }
        return null;
    }

    /**
     * Parse a compressed cross-reference stream (PDF 1.5+).
     * @param array<int, int> $objectOffsets
     */
    private function parseXRefStream(string $content, int $xrefOffset, array &$objectOffsets): ?int
    {
        if (!preg_match('/\d+\s+\d+\s+obj\b/', $content, $headerMatch, PREG_OFFSET_CAPTURE, $xrefOffset)) {
            return null;
        }
        $bodyStart = (int) $headerMatch[0][1] + strlen($headerMatch[0][0]);
        $endObjPos = strpos($content, 'endobj', $bodyStart);
        if ($endObjPos === false) {
            return null;
        }

        $objectBody = substr($content, $bodyStart, $endObjPos - $bodyStart);
        $streamInfo = $this->extractStreamInfoFromObjectBody($objectBody, []);
        if ($streamInfo === null) {
            return null;
        }

        $warnings = [];
        $decoded = $this->decodeStream($streamInfo['dictionary'], $streamInfo['stream'], $warnings, 0);
        if ($decoded === '') {
            return null;
        }

        $dict = $streamInfo['dictionary'];

        // /W field widths: [type-width, offset-width, gen-width]
        if (!preg_match('/\/W\s*\[([\s\d]+)\]/', $dict, $wMatch)) {
            return null;
        }
        $wValues = array_values(array_filter(
            array_map('intval', preg_split('/\s+/', trim($wMatch[1])) ?: []),
            static fn(int $v): bool => true
        ));
        if (count($wValues) < 3) {
            return null;
        }
        [$w0, $w1, $w2] = $wValues;
        $entrySize = $w0 + $w1 + $w2;
        if ($entrySize <= 0) {
            return null;
        }

        $totalObjects = preg_match('/\/Size\s+(\d+)/', $dict, $sm) === 1 ? (int) $sm[1] : 0;

        // /Index: pairs of [firstId, count]; defaults to [0, /Size]
        $indexRanges = [];
        if (preg_match('/\/Index\s*\[(.*?)\]/s', $dict, $indexMatch)) {
            $tokens = array_values(array_filter(
                preg_split('/\s+/', trim($indexMatch[1])) ?: [],
                static fn(string $v): bool => $v !== ''
            ));
            for ($i = 0; $i + 1 < count($tokens); $i += 2) {
                $indexRanges[] = [(int) $tokens[$i], (int) $tokens[$i + 1]];
            }
        }
        if ($indexRanges === []) {
            $indexRanges[] = [0, $totalObjects];
        }

        $dataOffset = 0;
        $dataLen = strlen($decoded);
        foreach ($indexRanges as [$startId, $rangeCount]) {
            for ($i = 0; $i < $rangeCount; $i++) {
                if ($dataOffset + $entrySize > $dataLen) {
                    break 2;
                }
                $objectId = $startId + $i;

                $type = 0;
                for ($b = 0; $b < $w0; $b++) {
                    $type = ($type << 8) | ord($decoded[$dataOffset++]);
                }
                if ($w0 === 0) {
                    $type = 1; // default when field is absent
                }

                $field1 = 0;
                for ($b = 0; $b < $w1; $b++) {
                    $field1 = ($field1 << 8) | ord($decoded[$dataOffset++]);
                }

                $field2 = 0;
                for ($b = 0; $b < $w2; $b++) {
                    $field2 = ($field2 << 8) | ord($decoded[$dataOffset++]);
                }

                // type 1 = uncompressed object at byte offset $field1
                // type 2 = compressed in obj stream (expandObjectStreams handles this)
                if ($type === 1 && !isset($objectOffsets[$objectId])) {
                    $objectOffsets[$objectId] = $field1;
                }
            }
        }

        if (preg_match('/\/Prev\s+(\d+)/', $dict, $prevMatch)) {
            return (int) $prevMatch[1];
        }
        return null;
    }

    private function locateEndObjOffset(string $content, int $searchOffset): ?int
    {
        $length = strlen($content);
        $cursor = max(0, $searchOffset);

        while ($cursor < $length) {
            // strpos is far faster than regex for the initial scan; confirm word boundary after
            $rawEndObj = strpos($content, 'endobj', $cursor);
            if ($rawEndObj === false) {
                return null;
            }
            // Verify \b boundary: preceding char must be non-word or start-of-string
            $before = $rawEndObj > 0 ? $content[$rawEndObj - 1] : ' ';
            $after  = isset($content[$rawEndObj + 6]) ? $content[$rawEndObj + 6] : ' ';
            if (
                (ctype_alnum($before) || $before === '_') ||
                (ctype_alnum($after)  || $after  === '_')
            ) {
                $cursor = $rawEndObj + 1;
                continue;
            }
            $endObjOffset = $rawEndObj;

            // Check whether a stream keyword precedes this endobj
            $rawStream = strpos($content, 'stream', $cursor);
            $hasStream = $rawStream !== false && $rawStream < $length;

            if (!$hasStream) {
                return $endObjOffset;
            }

            // Confirm the stream keyword is followed by CR, LF, or CRLF
            $streamOffset = null;
            $streamTokenLen = 0;
            for ($sp = $rawStream; $sp !== false && $sp < $endObjOffset; ) {
                $afterStream = $content[$sp + 6] ?? '';
                $afterStream2 = $content[$sp + 7] ?? '';
                if ($afterStream === "\n") {
                    $streamOffset = $sp;
                    $streamTokenLen = 7; // "stream\n"
                    break;
                }
                if ($afterStream === "\r" && $afterStream2 === "\n") {
                    $streamOffset = $sp;
                    $streamTokenLen = 8; // "stream\r\n"
                    break;
                }
                if ($afterStream === "\r") {
                    $streamOffset = $sp;
                    $streamTokenLen = 7; // "stream\r"
                    break;
                }
                $sp = strpos($content, 'stream', $sp + 1);
            }

            if ($streamOffset === null || $streamOffset > $endObjOffset) {
                return $endObjOffset;
            }

            $streamDataOffset = $streamOffset + $streamTokenLen;
            $dictionary = substr($content, $cursor, $streamOffset - $cursor);
            $declaredLength = $this->extractDirectStreamLength($dictionary === false ? '' : $dictionary);

            if ($declaredLength !== null && $declaredLength >= 0) {
                $cursor = min($length, $streamDataOffset + $declaredLength);
            } else {
                $cursor = $streamDataOffset;
            }

            // strpos for endstream, then check boundary
            $rawEndStream = strpos($content, 'endstream', $cursor);
            if ($rawEndStream === false) {
                return $endObjOffset;
            }
            if ($rawEndStream > $endObjOffset) {
                return $endObjOffset;
            }

            $cursor = $rawEndStream + 9;
        }

        return null;
    }

    private function extractDirectStreamLength(string $dictionary): ?int
    {
        if (preg_match('/\/Length\s+(\d+)\b/', $dictionary, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function findPatternOffset(string $pattern, string $content, int $offset): ?int
    {
        if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        return (int) $matches[0][1];
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     */
    private function expandObjectStreams(array &$objects, array &$warnings): void
    {
        // Fast pre-check: avoid per-object regex when no ObjStm exists at all
        $hasObjStm = false;
        foreach ($objects as $obj) {
            if (str_contains($obj->body, '/ObjStm')) {
                $hasObjStm = true;
                break;
            }
        }
        if (!$hasObjStm) {
            return;
        }

        foreach ($objects as $containerObject) {
            if (!preg_match('/\/Type\s*\/ObjStm\b/', $containerObject->body)) {
                continue;
            }

            $streamInfo = $this->extractStreamInfoFromObjectBody($containerObject->body, $objects);
            if ($streamInfo === null) {
                $warnings[] = 'Object stream ' . $containerObject->id . ' could not be read.';
                continue;
            }

            $decoded = $this->decodeStream(
                $streamInfo['dictionary'],
                $streamInfo['stream'],
                $warnings,
                $containerObject->id
            );

            if ($decoded === '') {
                $warnings[] = 'Object stream ' . $containerObject->id . ' produced empty decoded data.';
                continue;
            }

            if (
                preg_match('/\/N\s+(\d+)/', $streamInfo['dictionary'], $nMatch) !== 1 ||
                preg_match('/\/First\s+(\d+)/', $streamInfo['dictionary'], $fMatch) !== 1
            ) {
                $warnings[] = 'Object stream ' . $containerObject->id . ' missing /N or /First.';
                continue;
            }

            $count = min((int) $nMatch[1], 65535); // cap to prevent memory exhaustion via crafted /N
            $first = (int) $fMatch[1];

            $header = substr($decoded, 0, $first);
            $objectData = substr($decoded, $first);
            $tokens = preg_split('/\s+/', trim($header)) ?: [];

            if (count($tokens) < $count * 2) {
                $warnings[] = 'Object stream ' . $containerObject->id . ' index is shorter than expected.';
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $objectId = (int) $tokens[$i * 2];
                $offset = (int) $tokens[($i * 2) + 1];
                $nextOffset = ($i + 1 < $count)
                    ? (int) $tokens[(($i + 1) * 2) + 1]
                    : strlen($objectData);

                if ($nextOffset < $offset) {
                    continue;
                }

                $body = trim(substr($objectData, $offset, $nextOffset - $offset));
                if ($body === '') {
                    continue;
                }

                if (isset($objects[$objectId])) {
                    continue;
                }

                $objects[$objectId] = new PdfObject(
                    $objectId,
                    0,
                    $body,
                    $containerObject->offset + $offset,
                    true
                );
            }
        }
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return int[]
     */
    private function resolvePageObjectIds(array $objects, ?int $toPage = null): array
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

        return array_map(static fn(array $item): int => $item['id'], $pages);
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $seen
     * @return int[]
     */
    private function walkPageTree(int $objectId, array $objects, array &$seen, int $limit = PHP_INT_MAX): array
    {
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
            foreach ($this->walkPageTree($kidId, $objects, $seen, $limit) as $pageId) {
                $ids[] = $pageId;
                if (count($ids) >= $limit) {
                    break 2;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     */
    private function extractPageText(int $pageObjectId, array $objects, array &$warnings): string
    {
        if (!isset($objects[$pageObjectId])) {
            return '';
        }

        $pageBody = $objects[$pageObjectId]->body;
        $fontMaps = $this->buildPageFontMaps($pageObjectId, $pageBody, $objects, $warnings);
        $xObjectMap = $this->buildPageXObjectMap($pageObjectId, $pageBody, $objects);
        $contentObjectIds = $this->resolvePageContentObjectIds($pageBody, $objects);

        if ($contentObjectIds === []) {
            return '';
        }

        $streamTexts = [];
        foreach ($contentObjectIds as $contentObjectId) {
            if (!isset($objects[$contentObjectId])) {
                continue;
            }

            $streamInfo = $this->extractStreamInfoFromObjectBody($objects[$contentObjectId]->body, $objects);
            if ($streamInfo === null) {
                continue;
            }

            $decodedStream = $this->decodeStream(
                $streamInfo['dictionary'],
                $streamInfo['stream'],
                $warnings,
                $contentObjectId
            );

            if ($decodedStream === '') {
                continue;
            }

            $streamTexts[] = $this->extractTextFromContentStream(
                $decodedStream,
                $fontMaps,
                $xObjectMap,
                $objects,
                $warnings,
                []
            );
        }

        return trim(implode("\n\n", array_filter($streamTexts, static fn(string $s): bool => trim($s) !== '')));
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return array<string, int>
     */
    private function buildPageXObjectMap(int $pageObjectId, string $pageBody, array $objects): array
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
    private function resolvePageContentObjectIds(string $pageBody, array $objects): array
    {
        $ids = [];
        $visited = [];

        if (preg_match('/\/Contents\s*\[(.*?)\]/s', $pageBody, $arrayMatch) === 1) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $arrayMatch[1], $refs);
            foreach ($refs[1] as $refIdRaw) {
                $refId = (int) $refIdRaw;
                foreach ($this->resolveContentReferenceObjectIds($refId, $objects, $visited) as $streamId) {
                    $ids[] = $streamId;
                }
            }

            return $this->uniqueIds($ids);
        }

        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pageBody, $singleMatch) === 1) {
            $refId = (int) $singleMatch[1];
            foreach ($this->resolveContentReferenceObjectIds($refId, $objects, $visited) as $streamId) {
                $ids[] = $streamId;
            }
        }

        return $this->uniqueIds($ids);
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     * @return int[]
     */
    private function resolveContentReferenceObjectIds(int $objectId, array $objects, array &$visited): array
    {
        if (isset($visited[$objectId])) {
            return [];
        }
        $visited[$objectId] = true;

        if (!isset($objects[$objectId])) {
            return [];
        }

        $body = trim($objects[$objectId]->body);
        if ($body === '') {
            return [];
        }

        if (strpos($body, 'stream') !== false && strpos($body, 'endstream') !== false) {
            return [$objectId];
        }

        if (preg_match('/^\[(.*)\]$/s', $body, $arrayMatch) === 1) {
            $ids = [];
            preg_match_all('/(\d+)\s+\d+\s+R/', $arrayMatch[1], $refs);
            foreach ($refs[1] as $refIdRaw) {
                $refId = (int) $refIdRaw;
                foreach ($this->resolveContentReferenceObjectIds($refId, $objects, $visited) as $streamId) {
                    $ids[] = $streamId;
                }
            }

            return $ids;
        }

        if (preg_match('/^(\d+)\s+\d+\s+R$/', $body, $singleRefMatch) === 1) {
            return $this->resolveContentReferenceObjectIds((int) $singleRefMatch[1], $objects, $visited);
        }

        return [];
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private function uniqueIds(array $ids): array
    {
        $seen = [];
        $out = [];

        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     * @return array<string, array{map: array<string, string>, max_code_bytes: int}>
     */
    private function buildPageFontMaps(int $pageObjectId, string $pageBody, array $objects, array &$warnings): array
    {
        $fontMaps = [];

        $resourceBodies = $this->resolveResourceDictionaryBodies($pageObjectId, $pageBody, $objects);
        if ($resourceBodies === []) {
            return $fontMaps;
        }

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
                preg_match_all('/\/([A-Za-z0-9]+)\s+(\d+)\s+\d+\s+R/', $fontDictContent, $fontRefs, PREG_SET_ORDER);
                foreach ($fontRefs as $fontRef) {
                    $resourceName = $fontRef[1];
                    $fontObjectId = (int) $fontRef[2];

                    if (!isset($objects[$fontObjectId])) {
                        continue;
                    }

                    $fontBody = $objects[$fontObjectId]->body;
                    $mapData = $this->buildFontMapData($fontObjectId, $fontBody, $objects, $warnings);
                    $fontMaps[$resourceName] = $mapData;
                }
            }
        }

        return $fontMaps;
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
    private function buildFontMapData(int $fontObjectId, string $fontBody, array $objects, array &$warnings): array
    {
        if (isset($this->fontMapCache[$fontObjectId])) {
            return $this->fontMapCache[$fontObjectId];
        }

        $encodingData = $this->parseFontEncodingData($fontObjectId, $fontBody, $objects);
        $mapData = [
            'map' => [],
            'max_code_bytes' => $encodingData['is_multibyte'] ? 2 : 1,
            'encoding_name' => $encodingData['encoding_name'],
            'differences' => $encodingData['differences'],
            'is_multibyte' => $encodingData['is_multibyte'],
            'has_tounicode' => false,
        ];

        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fontBody, $toUnicodeMatch) !== 1) {
            return $mapData;
        }

        $toUnicodeObjectId = (int) $toUnicodeMatch[1];
        if (!isset($objects[$toUnicodeObjectId])) {
            return $mapData;
        }

        $streamInfo = $this->extractStreamInfoFromObjectBody($objects[$toUnicodeObjectId]->body, $objects);
        if ($streamInfo === null) {
            return $mapData;
        }

        $decodedCMap = $this->decodeStream(
            $streamInfo['dictionary'],
            $streamInfo['stream'],
            $warnings,
            $toUnicodeObjectId
        );

        if ($decodedCMap === '') {
            return $mapData;
        }

        // Guard against malformed PDFs embedding oversized CMap data (ReDoS / memory protection).
        // Legitimate ToUnicode CMap tables are never larger than ~100 KB.
        if (strlen($decodedCMap) > $this->options->maxCMapSize) {
            $warnings[] = 'ToUnicode CMap on object ' . $fontObjectId . ' exceeds 1 MB — truncated for safety.';
            $decodedCMap = substr($decodedCMap, 0, $this->options->maxCMapSize);
        }

        $toUnicodeMapData = $this->parseToUnicodeCMap($decodedCMap);
        if ($toUnicodeMapData['map'] !== []) {
            $mapData['map'] = $toUnicodeMapData['map'];
            $mapData['max_code_bytes'] = max($mapData['max_code_bytes'], $toUnicodeMapData['max_code_bytes']);
            $mapData['has_tounicode'] = true;
        }

        return $this->fontMapCache[$fontObjectId] = $mapData;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return string[]
     */
    private function resolveResourceDictionaryBodies(int $pageObjectId, string $pageBody, array $objects): array
    {
        $bodies = [];
        foreach ($this->extractResourceDictionaryBodiesFromObjectBody($pageBody, $objects) as $body) {
            $bodies[] = $body;
        }

        if ($bodies !== []) {
            return $bodies;
        }

        $currentPageId = $pageObjectId;
        $visited = [];
        while (isset($objects[$currentPageId]) && !isset($visited[$currentPageId])) {
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

        return $bodies;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return string[]
     */
    private function extractResourceDictionaryBodiesFromObjectBody(string $objectBody, array $objects): array
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

        $dictionary = $this->extractFirstDictionary(substr($body, $dictionaryStart));
        if ($dictionary === null) {
            return '';
        }

        return $dictionary;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     */
    private function resolveResourceObjectBody(int $objectId, array $objects, array $visited): string
    {
        return $this->resolveIndirectObjectBody($objectId, $objects, $visited);
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     */
    private function resolveIndirectObjectBody(int $objectId, array $objects, array $visited): string
    {
        if (isset($visited[$objectId]) || !isset($objects[$objectId])) {
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
            return $this->resolveIndirectObjectBody((int) $refMatch[1], $objects, $visited);
        }

        return '';
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array<int, bool> $visited
     */
    private function resolveIndirectObjectToken(int $objectId, array $objects, array $visited): string
    {
        if (isset($visited[$objectId]) || !isset($objects[$objectId])) {
            return '';
        }

        $visited[$objectId] = true;
        $body = trim($objects[$objectId]->body);
        if ($body === '') {
            return '';
        }

        if (preg_match('/^(\d+)\s+\d+\s+R$/', $body, $refMatch) === 1) {
            return $this->resolveIndirectObjectToken((int) $refMatch[1], $objects, $visited);
        }

        return $body;
    }

    /**
     * @return array<string, int>
     */
    private function extractXObjectMapFromResourceDictionary(string $resourceBody, array $objects): array
    {
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
                $map[$this->decodePdfNameEscapes($ref[1])] = (int) $ref[2];
            }
        }

        return $map;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return array{
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool
     * }
     */
    private function parseFontEncodingData(int $fontObjectId, string $fontBody, array $objects): array
    {
        $defaultEncodingName = $this->determineDefaultEncodingName($fontBody);
        $encodingName = $defaultEncodingName;
        $differences = [];
        $isMultibyte = preg_match('/\/Subtype\s*\/Type0\b/', $fontBody) === 1;

        if (preg_match('/\/Encoding\s*\/([A-Za-z0-9._-]+)/', $fontBody, $encodingNameMatch) === 1) {
            $encodingName = $encodingNameMatch[1];
        } elseif (preg_match('/\/Encoding\s*<<((?:[^>]|>(?!>))*+)>>/s', $fontBody, $encodingDictMatch) === 1) {
            $parsed = $this->parseEncodingDictionaryBody('<<' . $encodingDictMatch[1] . '>>');
            if ($parsed['base_encoding'] !== '') {
                $encodingName = $parsed['base_encoding'];
            }
            $differences = $parsed['differences'];
        } elseif (preg_match('/\/Encoding\s+(\d+)\s+\d+\s+R/', $fontBody, $encodingRefMatch) === 1) {
            $encodingObjectBody = $this->resolveIndirectObjectToken((int) $encodingRefMatch[1], $objects, []);
            if ($encodingObjectBody !== '') {
                if (preg_match('/^\/([A-Za-z0-9._-]+)$/', $encodingObjectBody, $encodingNameRefMatch) === 1) {
                    $encodingName = $encodingNameRefMatch[1];
                }

                $parsed = $this->parseEncodingDictionaryBody($encodingObjectBody);
                if ($parsed['base_encoding'] !== '') {
                    $encodingName = $parsed['base_encoding'];
                }
                $differences = $parsed['differences'];
            }
        }

        if (
            stripos($encodingName, 'Identity') !== false ||
            preg_match('/\/CIDToGIDMap\b/', $fontBody) === 1
        ) {
            $isMultibyte = true;
        }

        if (preg_match('/\/Subtype\s*\/Type0\b/', $fontBody) === 1 && $encodingName === '') {
            $encodingName = 'Identity';
            $isMultibyte = true;
        }

        return [
            'encoding_name' => $encodingName,
            'differences' => $differences,
            'is_multibyte' => $isMultibyte,
        ];
    }

    private function determineDefaultEncodingName(string $fontBody): string
    {
        if (preg_match('/\/BaseFont\s*\/([^\\s\\/<>\\[\\]()]+)/', $fontBody, $baseFontMatch) === 1) {
            $baseFont = strtolower($baseFontMatch[1]);
            if (str_contains($baseFont, 'symbol')) {
                return 'SymbolEncoding';
            }
            if (str_contains($baseFont, 'zapfdingbats')) {
                return 'ZapfDingbatsEncoding';
            }
        }

        if (preg_match('/\/Subtype\s*\/Type1\b/', $fontBody) === 1) {
            return 'StandardEncoding';
        }

        return 'WinAnsiEncoding';
    }

    /**
     * @return array{
     *     base_encoding: string,
     *     differences: array<int, string>
     * }
     */
    private function parseEncodingDictionaryBody(string $encodingBody): array
    {
        $baseEncoding = '';
        $differences = [];

        if (preg_match('/\/BaseEncoding\s*\/([A-Za-z0-9._-]+)/', $encodingBody, $baseEncodingMatch) === 1) {
            $baseEncoding = $baseEncodingMatch[1];
        }

        if (preg_match('/\/Differences\s*\[(.*?)\]/s', $encodingBody, $differencesMatch) === 1) {
            $differences = $this->parseDifferencesArray($differencesMatch[1]);
        }

        return [
            'base_encoding' => $baseEncoding,
            'differences' => $differences,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseDifferencesArray(string $differencesBody): array
    {
        $differences = [];
        preg_match_all('/\/([^\s\/<>\[\]\(\)\{\}%]+)|(\d+)/', $differencesBody, $tokens, PREG_SET_ORDER);

        $currentCode = null;
        foreach ($tokens as $token) {
            if (($token[2] ?? '') !== '') {
                $currentCode = (int) $token[2];
                continue;
            }

            if (($token[1] ?? '') === '' || $currentCode === null) {
                continue;
            }

            $differences[$currentCode] = $this->decodePdfNameEscapes($token[1]);
            $currentCode++;
        }

        return $differences;
    }

    private function decodePdfNameEscapes(string $name): string
    {
        return (string) preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            static fn(array $m): string => chr(hexdec($m[1])),
            $name
        );
    }

    /**
     * @return array{map: array<string, string>, max_code_bytes: int}
     */
    private function parseToUnicodeCMap(string $cmap): array
    {
        $map = [];
        $maxCodeBytes = 1;

        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $bfcharBlocks, PREG_SET_ORDER);
        foreach ($bfcharBlocks as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $src = strtoupper($pair[1]);
                $dst = strtoupper($pair[2]);
                $text = $this->hexToUtf8($dst);
                if ($text === '') {
                    continue;
                }

                $map[$src] = $text;
                $maxCodeBytes = max($maxCodeBytes, intdiv(strlen($src), 2));
            }
        }

        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $bfrangeBlocks, PREG_SET_ORDER);
        foreach ($bfrangeBlocks as $block) {
            $blockContent = $block[1];

            // First pass: extract all array-style entries, which may span multiple lines.
            // Remove them from the block so the scalar pass does not re-process them.
            if (
                preg_match_all(
                    '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[([\s\S]*?)\]/s',
                    $blockContent,
                    $arrayRangeMatches,
                    PREG_SET_ORDER
                ) > 0
            ) {
                foreach ($arrayRangeMatches as $arrayRangeMatch) {
                    $startCodeHex = strtoupper($arrayRangeMatch[1]);
                    $endCodeHex = strtoupper($arrayRangeMatch[2]);
                    $startCode = $this->parseCMapSourceCode($startCodeHex);
                    $endCode = $this->parseCMapSourceCode($endCodeHex);
                    if (
                        $startCode === null
                        || $endCode === null
                        || $endCode < $startCode
                        || ($endCode - $startCode) > 0xFFFF
                    ) {
                        continue;
                    }
                    $codeWidth = strlen($startCodeHex);

                    preg_match_all('/<([0-9A-Fa-f]+)>/', $arrayRangeMatch[3], $destinations);
                    $destinationHexValues = $destinations[1] ?? [];

                    $index = 0;
                    for ($code = $startCode; $code <= $endCode; $code++) {
                        if (!isset($destinationHexValues[$index])) {
                            break;
                        }

                        $sourceKey = strtoupper(str_pad(dechex($code), $codeWidth, '0', STR_PAD_LEFT));
                        $destHex = strtoupper($destinationHexValues[$index]);
                        $text = $this->hexToUtf8($destHex);
                        if ($text !== '') {
                            $map[$sourceKey] = $text;
                            $maxCodeBytes = max($maxCodeBytes, intdiv(strlen($sourceKey), 2));
                        }

                        $index++;
                    }
                }

                // Strip array entries so the scalar pass below does not accidentally
                // parse individual hex tokens from inside the array as a range.
                $blockContent = preg_replace(
                    '/<[0-9A-Fa-f]+>\s*<[0-9A-Fa-f]+>\s*\[[\s\S]*?\]/s',
                    '',
                    $blockContent
                ) ?? $blockContent;
            }

            // Second pass: scalar entries  <startHex> <endHex> <firstValueHex>
            $lines = preg_split('/[\r\n]+/', trim($blockContent)) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (
                    preg_match('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $line, $rangeMatch) !== 1
                ) {
                    continue;
                }

                $startCodeHex = strtoupper($rangeMatch[1]);
                $endCodeHex = strtoupper($rangeMatch[2]);
                $startValueHex = strtoupper($rangeMatch[3]);

                $startCode = $this->parseCMapSourceCode($startCodeHex);
                $endCode = $this->parseCMapSourceCode($endCodeHex);

                // Reject implausible ranges produced by mismatched surrogate or
                // binary data that leaked into the block content.
                if (
                    $startCode === null
                    || $endCode === null
                    || $endCode < $startCode
                    || ($endCode - $startCode) > 0xFFFF
                ) {
                    continue;
                }

                $codeWidth = strlen($startCodeHex);

                for ($code = $startCode; $code <= $endCode; $code++) {
                    $sourceKey = strtoupper(str_pad(dechex($code), $codeWidth, '0', STR_PAD_LEFT));
                    $destHex = $this->incrementHexString($startValueHex, $code - $startCode);
                    if ($destHex === null) {
                        continue;
                    }
                    $text = $this->hexToUtf8($destHex);
                    if ($text === '') {
                        continue;
                    }

                    $map[$sourceKey] = $text;
                    $maxCodeBytes = max($maxCodeBytes, intdiv(strlen($sourceKey), 2));
                }
            }
        }

        return [
            'map' => $map,
            'max_code_bytes' => $maxCodeBytes,
        ];
    }

    /**
     * PDF character codes are at most four bytes wide. Reject wider values
     * before hexdec() can return an out-of-range float on PHP 8.5.
     */
    private function parseCMapSourceCode(string $hex): ?int
    {
        if ($hex === '' || strlen($hex) > 8 || preg_match('/^[0-9A-Fa-f]+$/', $hex) !== 1) {
            return null;
        }

        $value = hexdec($hex);
        if (is_float($value)) {
            if ($value > PHP_INT_MAX) {
                return null;
            }

            return (int) $value;
        }

        return $value;
    }

    /**
     * Increment an arbitrarily wide hexadecimal byte string without converting
     * the complete value to an integer. ToUnicode destinations can be wider
     * than the platform integer size.
     */
    private function incrementHexString(string $hex, int $increment): ?string
    {
        if ($hex === '' || $increment < 0 || preg_match('/^[0-9A-Fa-f]+$/', $hex) !== 1) {
            return null;
        }

        $hex = strtoupper($hex);
        $carry = $increment;

        for ($index = strlen($hex) - 1; $index >= 0 && $carry > 0; $index--) {
            $sum = hexdec($hex[$index]) + $carry;
            $hex[$index] = strtoupper(dechex($sum % 16));
            $carry = intdiv($sum, 16);
        }

        while ($carry > 0) {
            $hex = strtoupper(dechex($carry % 16)) . $hex;
            $carry = intdiv($carry, 16);
        }

        return $hex;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return array{dictionary:string,stream:string}|null
     */
    private function extractStreamInfoFromObjectBody(string $objectBody, array $objects = []): ?array
    {
        $streamPos = strpos($objectBody, 'stream');
        if ($streamPos === false) {
            return null;
        }

        $dictionaryPart = substr($objectBody, 0, $streamPos);
        $dictionary = $this->extractFirstDictionary($dictionaryPart);
        if ($dictionary === null) {
            return null;
        }

        $streamStart = $streamPos + strlen('stream');
        if (isset($objectBody[$streamStart]) && $objectBody[$streamStart] === "\r") {
            $streamStart++;
        }
        if (isset($objectBody[$streamStart]) && $objectBody[$streamStart] === "\n") {
            $streamStart++;
        }

        $endStreamPos = strrpos($objectBody, 'endstream');
        if ($endStreamPos === false || $endStreamPos < $streamStart) {
            return null;
        }

        $stream = substr($objectBody, $streamStart, $endStreamPos - $streamStart);
        $declaredLength = $this->resolveStreamLength($dictionary, $objects);
        if ($declaredLength !== null && $declaredLength >= 0 && $declaredLength <= strlen($stream)) {
            $stream = substr($stream, 0, $declaredLength);
        }

        return [
            'dictionary' => $dictionary,
            'stream' => $stream,
        ];
    }

    /**
     * @param array<int, PdfObject> $objects
     */
    private function resolveStreamLength(string $dictionary, array $objects): ?int
    {
        if (preg_match('/\/Length\s+(\d+)\s+(\d+)\s+R\b/', $dictionary, $refMatch) === 1) {
            $lengthObjectId = (int) $refMatch[1];
            if (!isset($objects[$lengthObjectId])) {
                return null;
            }

            $lengthBody = trim($objects[$lengthObjectId]->body);
            if (preg_match('/^(\d+)$/', $lengthBody, $lengthMatch) === 1) {
                return (int) $lengthMatch[1];
            }

            return null;
        }

        if (preg_match('/\/Length\s+(\d+)\b/', $dictionary, $lengthMatch) === 1) {
            return (int) $lengthMatch[1];
        }

        return null;
    }

    private function extractFirstDictionary(string $text): ?string
    {
        $start = strpos($text, '<<');
        if ($start === false) {
            return null;
        }

        $length = strlen($text);
        $depth = 0;

        for ($i = $start; $i < $length - 1; $i++) {
            $two = $text[$i] . $text[$i + 1];
            if ($two === '<<') {
                $depth++;
                $i++;
                continue;
            }
            if ($two === '>>') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param string[] $warnings
     */
    private function decodeStream(string $dictionary, string $stream, array &$warnings, int $objectId): string
    {
        $filterPipeline = $this->parseFilterPipeline($dictionary);
        if ($filterPipeline === []) {
            return $stream;
        }

        $decoded = $stream;
        foreach ($filterPipeline as $filterStep) {
            $filter = $filterStep['filter'];
            $decodeParams = $filterStep['decode_params'];

            $result = match ($filter) {
                'FlateDecode', 'Fl' => $this->decodeFlate($decoded),
                'ASCIIHexDecode', 'AHx' => $this->decodeAsciiHex($decoded),
                'ASCII85Decode', 'A85' => $this->decodeAscii85($decoded),
                'LZWDecode', 'LZW' => $this->decodeLzw($decoded, $decodeParams),
                'RunLengthDecode', 'RL' => $this->decodeRunLength($decoded),
                default => false,
            };

            if ($result === false) {
                $warnings[] = 'Unsupported or failed stream filter "' . $filter . '" on object ' . $objectId . '.';
                return '';
            }

            $decoded = $result;

            if (in_array($filter, ['FlateDecode', 'Fl', 'LZWDecode', 'LZW'], true)) {
                $postPredictor = $this->applyPredictor($decoded, $decodeParams);
                if ($postPredictor === false) {
                    $warnings[] = 'Predictor decode failed for filter "' . $filter . '" on object ' . $objectId . '.';
                    return '';
                }
                $decoded = $postPredictor;
            }
        }

        return $decoded;
    }

    /**
     * @return array<int, array{filter: string, decode_params: array<string, int>}>
     */
    private function parseFilterPipeline(string $dictionary): array
    {
        $filters = [];

        if (preg_match('/\/Filter\s*\[(.*?)\]/s', $dictionary, $arrayMatch) === 1) {
            preg_match_all('/\/([A-Za-z0-9]+)/', $arrayMatch[1], $matches);
            $filters = $matches[1] ?? [];
        } elseif (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $dictionary, $singleMatch) === 1) {
            $filters = [$singleMatch[1]];
        }

        if ($filters === []) {
            return [];
        }

        $decodeParamsByIndex = [];
        if (preg_match('/\/DecodeParms\s*\[(.*?)\]/s', $dictionary, $decodeParmsArrayMatch) === 1) {
            $decodeParamsByIndex = $this->parseDecodeParmsArray($decodeParmsArrayMatch[1]);
        } elseif (preg_match('/\/DecodeParms\s*<<((?:[^>]|>(?!>))*+)>>/s', $dictionary, $decodeParmsDictMatch) === 1) {
            $decodeParamsByIndex[0] = $this->parseDecodeParmsDictionary('<<' . $decodeParmsDictMatch[1] . '>>');
        } elseif (preg_match('/\/DP\s*\[(.*?)\]/s', $dictionary, $dpArrayMatch) === 1) {
            $decodeParamsByIndex = $this->parseDecodeParmsArray($dpArrayMatch[1]);
        } elseif (preg_match('/\/DP\s*<<((?:[^>]|>(?!>))*+)>>/s', $dictionary, $dpDictMatch) === 1) {
            $decodeParamsByIndex[0] = $this->parseDecodeParmsDictionary('<<' . $dpDictMatch[1] . '>>');
        }

        $pipeline = [];
        foreach ($filters as $index => $filter) {
            $pipeline[] = [
                'filter' => $filter,
                'decode_params' => $decodeParamsByIndex[$index] ?? [],
            ];
        }

        return $pipeline;
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function parseDecodeParmsArray(string $decodeParmsArrayBody): array
    {
        $items = $this->parsePdfArrayItems($decodeParmsArrayBody);
        $params = [];

        foreach ($items as $index => $item) {
            $trimmed = trim($item);
            if ($trimmed === '' || $trimmed === 'null') {
                $params[$index] = [];
                continue;
            }

            if (str_starts_with($trimmed, '<<') && str_contains($trimmed, '>>')) {
                $params[$index] = $this->parseDecodeParmsDictionary($trimmed);
                continue;
            }

            $params[$index] = [];
        }

        return $params;
    }

    /**
     * @return array<string, int>
     */
    private function parseDecodeParmsDictionary(string $dictionaryBody): array
    {
        return [
            'Predictor' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['Predictor'], 1),
            'Colors' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['Colors'], 1),
            'BitsPerComponent' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['BitsPerComponent', 'BPC'], 8),
            'Columns' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['Columns'], 1),
            'EarlyChange' => $this->parseIntegerDictionaryValueAliases($dictionaryBody, ['EarlyChange'], 1),
        ];
    }

    /**
     * @param string[] $keys
     */
    private function parseIntegerDictionaryValueAliases(string $dictionaryBody, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            $value = $this->parseIntegerDictionaryValue($dictionaryBody, $key, PHP_INT_MIN);
            if ($value !== PHP_INT_MIN) {
                return $value;
            }
        }

        return $default;
    }

    private function parseIntegerDictionaryValue(string $dictionaryBody, string $key, int $default): int
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '\s+(-?\d+)/', $dictionaryBody, $match) === 1) {
            return (int) $match[1];
        }

        return $default;
    }

    /**
     * @return string[]
     */
    private function parsePdfArrayItems(string $arrayBody): array
    {
        $items = [];
        $length = strlen($arrayBody);
        $offset = 0;

        while ($offset < $length) {
            $this->skipContentWhitespaceAndComments($arrayBody, $offset);
            if ($offset >= $length) {
                break;
            }

            $items[] = $this->readPdfArrayItem($arrayBody, $offset);
        }

        return $items;
    }

    private function readPdfArrayItem(string $text, int &$offset): string
    {
        $length = strlen($text);
        if ($offset >= $length) {
            return '';
        }

        $start = $offset;
        $char = $text[$offset];

        if ($char === '<' && ($offset + 1) < $length && $text[$offset + 1] === '<') {
            $offset += 2;
            $depth = 1;

            while ($offset < $length && $depth > 0) {
                $current = $text[$offset];
                $next = $text[$offset + 1] ?? '';

                if ($current === '<' && $next === '<') {
                    $depth++;
                    $offset += 2;
                    continue;
                }

                if ($current === '>' && $next === '>') {
                    $depth--;
                    $offset += 2;
                    continue;
                }

                if ($current === '(') {
                    $this->readLiteralString($text, $offset);
                    continue;
                }

                $offset++;
            }

            return substr($text, $start, $offset - $start);
        }

        if ($char === '[') {
            $offset++;
            $depth = 1;

            while ($offset < $length && $depth > 0) {
                $current = $text[$offset];

                if ($current === '[') {
                    $depth++;
                    $offset++;
                    continue;
                }

                if ($current === ']') {
                    $depth--;
                    $offset++;
                    continue;
                }

                if ($current === '(') {
                    $this->readLiteralString($text, $offset);
                    continue;
                }

                if (
                    $current === '<' &&
                    ($offset + 1) < $length &&
                    $text[$offset + 1] === '<'
                ) {
                    $this->readPdfArrayItem($text, $offset);
                    continue;
                }

                $offset++;
            }

            return substr($text, $start, $offset - $start);
        }

        if ($char === '(') {
            $this->readLiteralString($text, $offset);
            return substr($text, $start, $offset - $start);
        }

        if ($char === '<') {
            $offset++;
            while ($offset < $length && $text[$offset] !== '>') {
                $offset++;
            }
            if ($offset < $length && $text[$offset] === '>') {
                $offset++;
            }

            return substr($text, $start, $offset - $start);
        }

        if ($char === '/') {
            $offset++;
            while ($offset < $length) {
                $current = $text[$offset];
                if ($this->isPdfWhitespace($current) || $this->isPdfDelimiter($current)) {
                    break;
                }
                $offset++;
            }

            return substr($text, $start, $offset - $start);
        }

        while ($offset < $length) {
            $current = $text[$offset];
            if ($this->isPdfWhitespace($current) || $this->isPdfDelimiter($current)) {
                break;
            }
            $offset++;
        }

        if ($offset === $start) {
            $offset++;
        }

        return substr($text, $start, $offset - $start);
    }

    private function decodeFlate(string $stream): string|false
    {
        // 100 MB output cap: prevents decompression bomb (zip bomb) attacks.
        $result = @zlib_decode($stream, 100 * 1024 * 1024);
        if ($result !== false) {
            return $result;
        }

        $result = @gzuncompress($stream, 100 * 1024 * 1024);
        if ($result !== false) {
            return $result;
        }

        $result = @gzinflate($stream, 100 * 1024 * 1024);
        if ($result !== false) {
            return $result;
        }

        return false;
    }

    private function decodeAsciiHex(string $stream): string|false
    {
        $clean = preg_replace('/\s+/', '', $stream);
        if ($clean === null) {
            return false;
        }

        $clean = rtrim($clean, '>');
        if ($clean === '') {
            return '';
        }

        if (strlen($clean) % 2 === 1) {
            $clean .= '0';
        }

        $decoded = @hex2bin($clean);
        if ($decoded === false) {
            return false;
        }

        return $decoded;
    }

    private function decodeAscii85(string $stream): string|false
    {
        $clean = preg_replace('/\s+/', '', $stream);
        if ($clean === null) {
            return false;
        }

        $clean = str_replace(['<~', '~>'], '', $clean);
        if ($clean === '') {
            return '';
        }

        $result = '';
        $group = '';
        $length = strlen($clean);

        for ($i = 0; $i < $length; $i++) {
            $char = $clean[$i];

            if ($char === 'z') {
                if ($group !== '') {
                    return false;
                }
                $result .= "\x00\x00\x00\x00";
                continue;
            }

            $ord = ord($char);
            if ($ord < 33 || $ord > 117) {
                continue;
            }

            $group .= $char;

            if (strlen($group) === 5) {
                $value = 0;
                for ($j = 0; $j < 5; $j++) {
                    $value = ($value * 85) + (ord($group[$j]) - 33);
                }

                $result .= pack('N', $value);
                $group = '';
            }
        }

        if ($group !== '') {
            $missing = 5 - strlen($group);
            $group .= str_repeat('u', $missing);

            $value = 0;
            for ($j = 0; $j < 5; $j++) {
                $value = ($value * 85) + (ord($group[$j]) - 33);
            }

            $packed = pack('N', $value);
            $result .= substr($packed, 0, 4 - $missing);
        }

        return $result;
    }

    /**
     * @param array<string, int> $decodeParams
     */
    private function decodeLzw(string $stream, array $decodeParams): string|false
    {
        $dataLength = strlen($stream);
        if ($dataLength === 0) {
            return '';
        }

        $earlyChange = ($decodeParams['EarlyChange'] ?? 1) === 0 ? 0 : 1;
        $clearCode = 256;
        $eodCode = 257;
        $maxCode = 4095;
        $bitLength = $dataLength * 8;
        $bitOffset = 0;

        $dictionary = [];
        for ($i = 0; $i <= 255; $i++) {
            $dictionary[$i] = chr($i);
        }
        $codeWidth = 9;
        $nextCode = 258;
        $previousCode = null;
        $output = '';

        while (true) {
            // Inline bit reader (replaces closure to avoid per-iteration call overhead)
            if ($bitOffset + $codeWidth > $bitLength) {
                break;
            }
            $code = 0;
            for ($i = 0; $i < $codeWidth; $i++) {
                $absoluteBit = $bitOffset + $i;
                $byteIndex = intdiv($absoluteBit, 8);
                $bitIndex = 7 - ($absoluteBit % 8);
                $bit = (ord($stream[$byteIndex]) >> $bitIndex) & 1;
                $code = ($code << 1) | $bit;
            }
            $bitOffset += $codeWidth;

            if ($code === $clearCode) {
                $dictionary = [];
                for ($i = 0; $i <= 255; $i++) {
                    $dictionary[$i] = chr($i);
                }
                $codeWidth = 9;
                $nextCode = 258;
                $previousCode = null;
                continue;
            }

            if ($code === $eodCode) {
                break;
            }

            if (isset($dictionary[$code])) {
                $entry = $dictionary[$code];
            } elseif ($previousCode !== null && $code === $nextCode) {
                $previousValue = $dictionary[$previousCode] ?? '';
                if ($previousValue === '') {
                    return false;
                }
                $entry = $previousValue . $previousValue[0];
            } else {
                return false;
            }

            $output .= $entry;
            if (strlen($output) > 100 * 1024 * 1024) {
                return false; // decompression bomb guard
            }

            if ($previousCode !== null) {
                $previousValue = $dictionary[$previousCode] ?? '';
                if ($previousValue !== '' && $nextCode <= $maxCode) {
                    $dictionary[$nextCode] = $previousValue . $entry[0];
                    $nextCode++;

                    $threshold = (1 << $codeWidth) - $earlyChange;
                    if ($codeWidth < 12 && $nextCode >= $threshold) {
                        $codeWidth++;
                    }
                }
            }

            $previousCode = $code;
        }

        return $output;
    }

    private function decodeRunLength(string $stream): string|false
    {
        $length = strlen($stream);
        if ($length === 0) {
            return '';
        }

        $out = '';
        $offset = 0;

        while ($offset < $length) {
            $runLength = ord($stream[$offset]);
            $offset++;

            if ($runLength === 128) {
                break;
            }

            if ($runLength <= 127) {
                $literalLength = $runLength + 1;
                if ($offset + $literalLength > $length) {
                    return false;
                }

                $out .= substr($stream, $offset, $literalLength);
                $offset += $literalLength;
                continue;
            }

            if ($offset >= $length) {
                return false;
            }

            $repeatCount = 257 - $runLength;
            $out .= str_repeat($stream[$offset], $repeatCount);
            $offset++;
        }

        return $out;
    }

    /**
     * @param array<string, int> $decodeParams
     */
    private function applyPredictor(string $data, array $decodeParams): string|false
    {
        $predictor = (int) ($decodeParams['Predictor'] ?? 1);
        if ($predictor <= 1) {
            return $data;
        }

        $colors = max(1, (int) ($decodeParams['Colors'] ?? 1));
        $bitsPerComponent = max(1, (int) ($decodeParams['BitsPerComponent'] ?? 8));
        $columns = max(1, (int) ($decodeParams['Columns'] ?? 1));

        if ($bitsPerComponent % 8 !== 0) {
            return false;
        }

        $bytesPerPixel = max(1, intdiv(($colors * $bitsPerComponent) + 7, 8));
        $rowBytes = intdiv(($columns * $colors * $bitsPerComponent) + 7, 8);

        if ($rowBytes <= 0 || $rowBytes > 65536) {
            return false; // sanity cap: text PDFs never need rows wider than 64 KB
        }

        if ($predictor === 2) {
            return $this->applyTiffPredictor($data, $rowBytes, $bytesPerPixel);
        }

        if ($predictor >= 10) {
            return $this->applyPngPredictor($data, $rowBytes, $bytesPerPixel);
        }

        return $data;
    }

    private function applyTiffPredictor(string $data, int $rowBytes, int $bytesPerPixel): string|false
    {
        $length = strlen($data);
        if ($length === 0) {
            return '';
        }

        $out = '';
        $offset = 0;

        while ($offset < $length) {
            $row = substr($data, $offset, $rowBytes);
            $rowLength = strlen($row);
            if ($rowLength === 0) {
                break;
            }

            for ($i = $bytesPerPixel; $i < $rowLength; $i++) {
                $left = ord($row[$i - $bytesPerPixel]);
                $value = (ord($row[$i]) + $left) & 0xFF;
                $row[$i] = chr($value);
            }

            $out .= $row;
            $offset += $rowLength;
        }

        return $out;
    }

    private function applyPngPredictor(string $data, int $rowBytes, int $bytesPerPixel): string|false
    {
        $length = strlen($data);
        if ($length === 0) {
            return '';
        }

        $offset = 0;
        $out = '';
        $previousRow = str_repeat("\x00", $rowBytes);

        while ($offset < $length) {
            if ($offset + 1 > $length) {
                break;
            }

            $filterType = ord($data[$offset]);
            $offset++;

            if ($offset + $rowBytes > $length) {
                return false;
            }

            $encodedRow = substr($data, $offset, $rowBytes);
            $offset += $rowBytes;

            $decodedRow = str_repeat("\x00", $rowBytes);
            for ($i = 0; $i < $rowBytes; $i++) {
                $raw = ord($encodedRow[$i]);
                $left = $i >= $bytesPerPixel ? ord($decodedRow[$i - $bytesPerPixel]) : 0;
                $up = ord($previousRow[$i]);
                $upLeft = $i >= $bytesPerPixel ? ord($previousRow[$i - $bytesPerPixel]) : 0;

                $value = match ($filterType) {
                    0 => $raw,
                    1 => ($raw + $left) & 0xFF,
                    2 => ($raw + $up) & 0xFF,
                    3 => ($raw + intdiv($left + $up, 2)) & 0xFF,
                    4 => ($raw + $this->paethPredictor($left, $up, $upLeft)) & 0xFF,
                    default => -1,
                };

                if ($value < 0) {
                    return false;
                }

                $decodedRow[$i] = chr($value);
            }

            $out .= $decodedRow;
            $previousRow = $decodedRow;
        }

        return $out;
    }

    private function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }

    /**
     * @param array<string, array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * }> $fontMaps
     * @param array<string, int> $xObjectMap
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     * @param array<int, bool> $formStack
     */
    private function extractTextFromContentStream(
        string $contentStream,
        array $fontMaps,
        array $xObjectMap,
        array $objects,
        array &$warnings,
        array $formStack
    ): string
    {
        $offset = 0;
        $length = strlen($contentStream);
        $operands = [];
        $parts = [];
        $lastChar = '';
        $insideTextObject = false;
        $currentFont = null;

        while ($offset < $length) {
            $token = $this->readContentToken($contentStream, $offset);
            if ($token === null) {
                break;
            }

            if ($token['type'] !== 'operator') {
                if (count($operands) < 1024) {
                    $operands[] = $token;
                }
                continue;
            }

            $operator = $token['value'];

            switch ($operator) {
                case 'BT':
                    $insideTextObject = true;
                    $operands = [];
                    break;
                case 'ET':
                    $insideTextObject = false;
                    $this->appendNewlineToArray($parts, $lastChar);
                    $operands = [];
                    break;
                case 'Tj':
                    if ($insideTextObject && $operands !== []) {
                        $text = $this->tokenToText($operands[count($operands) - 1], $currentFont, $fontMaps);
                        if ($text !== '') {
                            $parts[] = $text;
                            $lastChar = $text[strlen($text) - 1];
                        }
                    }
                    $operands = [];
                    break;
                case 'TJ':
                    if ($insideTextObject && $operands !== []) {
                        $candidate = $operands[count($operands) - 1];
                        if ($candidate['type'] === 'array') {
                            $tjText = $this->extractTextFromTJArray($candidate['value'], $currentFont, $fontMaps);
                            if ($tjText !== '') {
                                $parts[] = $tjText;
                                $lastChar = $tjText[strlen($tjText) - 1];
                            }
                        }
                    }
                    $operands = [];
                    break;
                case '\'':
                    if ($insideTextObject) {
                        $this->appendNewlineToArray($parts, $lastChar);
                        if ($operands !== []) {
                            $text = $this->tokenToText($operands[count($operands) - 1], $currentFont, $fontMaps);
                            if ($text !== '') {
                                $parts[] = $text;
                                $lastChar = $text[strlen($text) - 1];
                            }
                        }
                    }
                    $operands = [];
                    break;
                case '"':
                    if ($insideTextObject) {
                        $this->appendNewlineToArray($parts, $lastChar);
                        if ($operands !== []) {
                            $text = $this->tokenToText($operands[count($operands) - 1], $currentFont, $fontMaps);
                            if ($text !== '') {
                                $parts[] = $text;
                                $lastChar = $text[strlen($text) - 1];
                            }
                        }
                    }
                    $operands = [];
                    break;
                case 'Tf':
                    if ($operands !== []) {
                        for ($i = count($operands) - 1; $i >= 0; $i--) {
                            if ($operands[$i]['type'] === 'name') {
                                $currentFont = (string) $operands[$i]['value'];
                                break;
                            }
                        }
                    }
                    $operands = [];
                    break;
                case 'Td':
                case 'TD':
                    if ($insideTextObject) {
                        // Only add a newline when the Y displacement is non-zero.
                        // A zero Y value is a horizontal-only advance on the same
                        // text line and must NOT break the word being assembled.
                        $yOperand = 0.0;
                        if (count($operands) >= 2) {
                            $last = $operands[count($operands) - 1];
                            if ($last['type'] === 'number') {
                                $yOperand = (float) $last['value'];
                            }
                        }
                        if (abs($yOperand) > 0.001) {
                            $this->appendNewlineToArray($parts, $lastChar);
                        }
                    }
                    $operands = [];
                    break;
                case 'T*':
                case 'Tm':
                    if ($insideTextObject) {
                        $this->appendNewlineToArray($parts, $lastChar);
                    }
                    $operands = [];
                    break;
                case 'Do':
                    $xObjectName = $this->extractLastNameOperand($operands);
                    if ($xObjectName !== null) {
                        $nestedText = $this->extractNestedFormXObjectText(
                            $xObjectName,
                            $xObjectMap,
                            $fontMaps,
                            $objects,
                            $warnings,
                            $formStack
                        );
                        if ($nestedText !== '') {
                            if ($parts !== []) {
                                $this->appendNewlineToArray($parts, $lastChar);
                            }
                            $parts[] = $nestedText;
                            $lastChar = $nestedText[strlen($nestedText) - 1];
                        }
                    }
                    $operands = [];
                    break;
                default:
                    $operands = [];
            }
        }

        return trim(implode('', $parts));
    }

    private function extractLastNameOperand(array $operands): ?string
    {
        for ($i = count($operands) - 1; $i >= 0; $i--) {
            if (($operands[$i]['type'] ?? '') === 'name') {
                return (string) $operands[$i]['value'];
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $xObjectMap
     * @param array<string, array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * }> $parentFontMaps
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     * @param array<int, bool> $formStack
     */
    private function extractNestedFormXObjectText(
        string $xObjectName,
        array $xObjectMap,
        array $parentFontMaps,
        array $objects,
        array &$warnings,
        array $formStack
    ): string
    {
        if (!isset($xObjectMap[$xObjectName])) {
            return '';
        }

        $xObjectId = $xObjectMap[$xObjectName];
        if (!isset($objects[$xObjectId])) {
            return '';
        }

        if (isset($formStack[$xObjectId])) {
            $warnings[] = 'Recursive Form XObject reference detected at object ' . $xObjectId . '.';
            return '';
        }

        $xObjectBody = $objects[$xObjectId]->body;
        if (
            preg_match('/\/Type\s*\/XObject\b/', $xObjectBody) !== 1 ||
            preg_match('/\/Subtype\s*\/Form\b/', $xObjectBody) !== 1
        ) {
            return '';
        }

        $streamInfo = $this->extractStreamInfoFromObjectBody($xObjectBody, $objects);
        if ($streamInfo === null) {
            return '';
        }

        $decodedStream = $this->decodeStream($streamInfo['dictionary'], $streamInfo['stream'], $warnings, $xObjectId);
        if ($decodedStream === '') {
            return '';
        }

        $resourceBodies = $this->extractResourceDictionaryBodiesFromObjectBody($xObjectBody, $objects);
        $formFontMaps = $parentFontMaps;
        $formXObjectMap = $xObjectMap;
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
                preg_match_all('/\/([A-Za-z0-9]+)\s+(\d+)\s+\d+\s+R/', $fontDictContent, $fontRefs, PREG_SET_ORDER);
                foreach ($fontRefs as $fontRef) {
                    $resourceName = $fontRef[1];
                    $fontObjectId = (int) $fontRef[2];
                    if (!isset($objects[$fontObjectId])) {
                        continue;
                    }
                    $formFontMaps[$resourceName] = $this->buildFontMapData($fontObjectId, $objects[$fontObjectId]->body, $objects, $warnings);
                }
            }

            foreach ($this->extractXObjectMapFromResourceDictionary($resourceBody, $objects) as $name => $objectId) {
                $formXObjectMap[$name] = $objectId;
            }
        }

        $formStack[$xObjectId] = true;

        return $this->extractTextFromContentStream(
            $decodedStream,
            $formFontMaps,
            $formXObjectMap,
            $objects,
            $warnings,
            $formStack
        );
    }

    /**
     * @param array<int, array{type:string,value:mixed}> $tokens
     * @param array<string, array{map: array<string, string>, max_code_bytes: int}> $fontMaps
     */
    private function extractTextFromTJArray(array $tokens, ?string $fontKey, array $fontMaps): string
    {
        $parts = [];

        foreach ($tokens as $token) {
            if ($token['type'] === 'string') {
                $text = $this->decodeTextBytes($token['value'], $fontKey, $fontMaps);
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }

            if ($token['type'] === 'number') {
                $value = (float) $token['value'];
                if ($value < -250) {
                    $parts[] = ' ';
                }
            }
        }

        return implode('', $parts);
    }

    /**
     * @return array{type:string,value:mixed}|null
     */
    private function readContentToken(string $content, int &$offset): ?array
    {
        $length = strlen($content);

        while (true) {
            $this->skipContentWhitespaceAndComments($content, $offset);

            if ($offset >= $length) {
                return null;
            }

            $char = $content[$offset];

            if ($char === '(') {
                return ['type' => 'string', 'value' => $this->readLiteralString($content, $offset)];
            }

            if ($char === '<') {
                if (($offset + 1) < $length && $content[$offset + 1] === '<') {
                    $offset += 2;
                    return ['type' => 'operator', 'value' => '<<'];
                }

                return ['type' => 'string', 'value' => $this->readHexString($content, $offset)];
            }

            if ($char === '>') {
                if (($offset + 1) < $length && $content[$offset + 1] === '>') {
                    $offset += 2;
                    return ['type' => 'operator', 'value' => '>>'];
                }

                $offset++;
                return ['type' => 'operator', 'value' => '>'];
            }

            if ($char === '[') {
                return ['type' => 'array', 'value' => $this->readArray($content, $offset)];
            }

            if ($char === ']') {
                $offset++;
                return ['type' => 'operator', 'value' => ']'];
            }

            if ($char === '/') {
                return ['type' => 'name', 'value' => $this->readName($content, $offset)];
            }

            if ($char === '\'' || $char === '"') {
                $offset++;
                return ['type' => 'operator', 'value' => $char];
            }

            if ($this->isNumberStart($content, $offset)) {
                return ['type' => 'number', 'value' => $this->readNumber($content, $offset)];
            }

            $word = $this->readWord($content, $offset);
            if ($word === '') {
                $offset++; // skip unrecognised byte; loop instead of recursing
                continue;
            }

            return [
                'type' => 'operator',
                'value' => $word,
            ];
        }
    }

    /**
     * @param array<string, array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * }> $fontMaps
     */
    private function tokenToText(array $token, ?string $fontKey, array $fontMaps): string
    {
        return match ($token['type']) {
            'string' => $this->decodeTextBytes((string) $token['value'], $fontKey, $fontMaps),
            'array' => $this->extractTextFromTJArray((array) $token['value'], $fontKey, $fontMaps),
            default => '',
        };
    }

    /**
     * @param array<string, array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * }> $fontMaps
     */
    private function decodeTextBytes(string $bytes, ?string $fontKey, array $fontMaps): string
    {
        if ($bytes === '') {
            return '';
        }

        if ($fontKey !== null && isset($fontMaps[$fontKey])) {
            $fontDef = $fontMaps[$fontKey];
            if ($fontDef['map'] !== []) {
                $mapped = $this->decodeWithFontMap($bytes, $fontDef['map'], $fontDef['max_code_bytes'], $fontDef);
                if ($mapped !== '') {
                    return $this->sanitizeText($mapped);
                }
            }

            $fallbackMapped = $this->decodeWithFontEncodingFallback($bytes, $fontDef);
            if ($fallbackMapped !== '') {
                return $this->sanitizeText($fallbackMapped);
            }
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return $this->convertEncoding(substr($bytes, 2), 'UTF-16BE');
        }

        if (str_starts_with($bytes, "\xFF\xFE")) {
            return $this->convertEncoding(substr($bytes, 2), 'UTF-16LE');
        }

        if (preg_match('//u', $bytes) === 1) {
            return $this->sanitizeText($bytes);
        }

        $converted = $this->convertEncoding($bytes, 'Windows-1252');
        if ($converted !== '') {
            return $this->sanitizeText($converted);
        }

        return $this->sanitizeText($bytes);
    }

    /**
     * @param array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * } $fontDef
     */
    private function decodeWithFontEncodingFallback(string $bytes, array $fontDef): string
    {
        if ($bytes === '') {
            return '';
        }

        $encodingName = $fontDef['encoding_name'] ?? '';
        $differences = $fontDef['differences'] ?? [];
        $isMultibyte = (bool) ($fontDef['is_multibyte'] ?? false);

        if ($isMultibyte) {
            if (str_starts_with($encodingName, 'Identity')) {
                $converted = $this->convertEncoding($bytes, 'UTF-16BE');
                if ($converted !== '') {
                    return $converted;
                }
            }

            $out = '';
            $length = strlen($bytes);
            for ($i = 0; $i + 1 < $length; $i += 2) {
                $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);

                if (isset($differences[$code])) {
                    $mapped = $this->glyphNameToUnicode($differences[$code]);
                    if ($mapped !== '') {
                        $out .= $mapped;
                        continue;
                    }
                }

                if ($code >= 32 && $code <= 126) {
                    $out .= chr($code);
                    continue;
                }

                $out .= $this->unicodeCodePointToUtf8($code);
            }

            return $out;
        }

        $out = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);

            if (isset($differences[$code])) {
                $mapped = $this->glyphNameToUnicode($differences[$code]);
                if ($mapped !== '') {
                    $out .= $mapped;
                    continue;
                }
            }

            $out .= $this->decodeSingleByteByEncoding($code, $encodingName);
        }

        return $out;
    }

    private function decodeSingleByteByEncoding(int $code, string $encodingName): string
    {
        if ($code < 0 || $code > 255) {
            return '';
        }

        return match ($encodingName) {
            'MacRomanEncoding' => $this->convertEncoding(chr($code), 'Macintosh'),
            'PDFDocEncoding' => $this->decodePdfDocByte($code),
            'StandardEncoding' => $this->decodeStandardEncodingByte($code),
            'SymbolEncoding' => $this->decodeStandardEncodingByte($code),
            'ZapfDingbatsEncoding' => $this->decodeStandardEncodingByte($code),
            default => $this->convertEncoding(chr($code), 'Windows-1252'),
        };
    }

    private function decodeStandardEncodingByte(int $code): string
    {
        if ($code >= 32 && $code <= 126) {
            return chr($code);
        }

        return match ($code) {
            9 => "\t",
            10 => "\n",
            13 => "\n",
            default => $this->convertEncoding(chr($code), 'Windows-1252'),
        };
    }

    private function decodePdfDocByte(int $code): string
    {
        static $pdfDocMap = [
            0x18 => 0x02D8, 0x19 => 0x02C7, 0x1A => 0x02C6, 0x1B => 0x02D9, 0x1C => 0x02DD,
            0x1D => 0x02DB, 0x1E => 0x02DA, 0x1F => 0x02DC, 0x80 => 0x2022, 0x81 => 0x2020,
            0x82 => 0x2021, 0x83 => 0x2026, 0x84 => 0x2014, 0x85 => 0x2013, 0x86 => 0x0192,
            0x87 => 0x2044, 0x88 => 0x2039, 0x89 => 0x203A, 0x8A => 0x2212, 0x8B => 0x2030,
            0x8C => 0x201E, 0x8D => 0x201C, 0x8E => 0x201D, 0x8F => 0x2018, 0x90 => 0x2019,
            0x91 => 0x201A, 0x92 => 0x2122, 0x93 => 0xFB01, 0x94 => 0xFB02, 0x95 => 0x0141,
            0x96 => 0x0152, 0x97 => 0x0160, 0x98 => 0x0178, 0x99 => 0x017D, 0x9A => 0x0131,
            0x9B => 0x0142, 0x9C => 0x0153, 0x9D => 0x0161, 0x9E => 0x017E, 0xA0 => 0x20AC,
        ];

        if (isset($pdfDocMap[$code])) {
            return $this->unicodeCodePointToUtf8($pdfDocMap[$code]);
        }

        if ($code >= 32 && $code <= 126) {
            return chr($code);
        }

        return $this->convertEncoding(chr($code), 'Windows-1252');
    }

    private function glyphNameToUnicode(string $glyphName): string
    {
        if ($glyphName === '' || $glyphName === '.notdef') {
            return '';
        }

        $normalizedName = $glyphName;
        if (str_contains($normalizedName, '.')) {
            $normalizedName = explode('.', $normalizedName, 2)[0];
        }

        if (str_contains($normalizedName, '_')) {
            $parts = explode('_', $normalizedName);
            $out = '';
            foreach ($parts as $part) {

                $out .= $this->glyphNameToUnicode($part);
            }
            return $out;
        }

        if (preg_match('/^uni([0-9A-Fa-f]{4,})$/', $normalizedName, $uniMatch) === 1) {
            $hex = strtoupper($uniMatch[1]);
            if (strlen($hex) % 4 === 0) {
                $out = '';
                for ($i = 0; $i < strlen($hex); $i += 4) {
                    $out .= $this->unicodeCodePointToUtf8(hexdec(substr($hex, $i, 4)));
                }
                return $out;
            }
        }

        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $normalizedName, $uMatch) === 1) {
            return $this->unicodeCodePointToUtf8(hexdec($uMatch[1]));
        }

        if (strlen($normalizedName) === 1) {
            return $normalizedName;
        }

        static $basicGlyphMap = [
            'space' => ' ', 'nbspace' => ' ', 'nonbreakingspace' => ' ', 'hyphen' => '-', 'endash' => '–',
            'emdash' => '—', 'quoteleft' => '‘', 'quoteright' => '’', 'quotedblleft' => '“',
            'quotedblright' => '”', 'quotesingle' => "'", 'quotedbl' => '"', 'comma' => ',',
            'period' => '.', 'colon' => ':', 'semicolon' => ';', 'exclam' => '!', 'question' => '?',
            'parenleft' => '(', 'parenright' => ')', 'bracketleft' => '[', 'bracketright' => ']',
            'braceleft' => '{', 'braceright' => '}', 'slash' => '/', 'backslash' => '\\', 'bar' => '|',
            'underscore' => '_', 'plus' => '+', 'equal' => '=', 'asterisk' => '*', 'ampersand' => '&',
            'at' => '@', 'numbersign' => '#', 'percent' => '%', 'dollar' => '$', 'less' => '<',
            'greater' => '>', 'asciitilde' => '~', 'asciicircum' => '^', 'grave' => '`', 'tilde' => '~',
            'fi' => 'fi', 'fl' => 'fl', 'ffi' => 'ffi', 'ffl' => 'ffl',
            'Euro' => '€', 'bullet' => '•', 'ellipsis' => '…', 'copyright' => '©', 'registered' => '®',
            'trademark' => '™', 'degree' => '°', 'plusminus' => '±', 'multiply' => '×', 'divide' => '÷',
            'Agrave' => 'À', 'Aacute' => 'Á', 'Acircumflex' => 'Â', 'Atilde' => 'Ã', 'Adieresis' => 'Ä',
            'Aring' => 'Å', 'AE' => 'Æ', 'Ccedilla' => 'Ç', 'Egrave' => 'È', 'Eacute' => 'É',
            'Ecircumflex' => 'Ê', 'Edieresis' => 'Ë', 'Igrave' => 'Ì', 'Iacute' => 'Í',
            'Icircumflex' => 'Î', 'Idieresis' => 'Ï', 'Eth' => 'Ð', 'Ntilde' => 'Ñ', 'Ograve' => 'Ò',
            'Oacute' => 'Ó', 'Ocircumflex' => 'Ô', 'Otilde' => 'Õ', 'Odieresis' => 'Ö', 'Oslash' => 'Ø',
            'Ugrave' => 'Ù', 'Uacute' => 'Ú', 'Ucircumflex' => 'Û', 'Udieresis' => 'Ü', 'Yacute' => 'Ý',
            'Thorn' => 'Þ', 'germandbls' => 'ß', 'agrave' => 'à', 'aacute' => 'á', 'acircumflex' => 'â',
            'atilde' => 'ã', 'adieresis' => 'ä', 'aring' => 'å', 'ae' => 'æ', 'ccedilla' => 'ç',
            'egrave' => 'è', 'eacute' => 'é', 'ecircumflex' => 'ê', 'edieresis' => 'ë', 'igrave' => 'ì',
            'iacute' => 'í', 'icircumflex' => 'î', 'idieresis' => 'ï', 'eth' => 'ð', 'ntilde' => 'ñ',
            'ograve' => 'ò', 'oacute' => 'ó', 'ocircumflex' => 'ô', 'otilde' => 'õ', 'odieresis' => 'ö',
            'oslash' => 'ø', 'ugrave' => 'ù', 'uacute' => 'ú', 'ucircumflex' => 'û', 'udieresis' => 'ü',
            'yacute' => 'ý', 'thorn' => 'þ', 'ydieresis' => 'ÿ', 'Ydieresis' => 'Ÿ',
            'Lslash' => 'Ł', 'lslash' => 'ł', 'Scaron' => 'Š', 'scaron' => 'š',
            'Zcaron' => 'Ž', 'zcaron' => 'ž', 'OE' => 'Œ', 'oe' => 'œ', 'dotlessi' => 'ı',
            'Alpha' => 'Α', 'Beta' => 'Β', 'Gamma' => 'Γ', 'Delta' => 'Δ', 'Epsilon' => 'Ε', 'Zeta' => 'Ζ',
            'Eta' => 'Η', 'Theta' => 'Θ', 'Iota' => 'Ι', 'Kappa' => 'Κ', 'Lambda' => 'Λ', 'Mu' => 'Μ',
            'Nu' => 'Ν', 'Xi' => 'Ξ', 'Omicron' => 'Ο', 'Pi' => 'Π', 'Rho' => 'Ρ', 'Sigma' => 'Σ',
            'Tau' => 'Τ', 'Upsilon' => 'Υ', 'Phi' => 'Φ', 'Chi' => 'Χ', 'Psi' => 'Ψ', 'Omega' => 'Ω',
            'alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ', 'delta' => 'δ', 'epsilon' => 'ε', 'zeta' => 'ζ',
            'eta' => 'η', 'theta' => 'θ', 'iota' => 'ι', 'kappa' => 'κ', 'lambda' => 'λ', 'mu' => 'μ',
            'nu' => 'ν', 'xi' => 'ξ', 'omicron' => 'ο', 'pi' => 'π', 'rho' => 'ρ', 'sigma' => 'σ',
            'tau' => 'τ', 'upsilon' => 'υ', 'phi' => 'φ', 'chi' => 'χ', 'psi' => 'ψ', 'omega' => 'ω',
            'sigma1' => 'ς', 'partialdiff' => '∂', 'summation' => '∑', 'product' => '∏', 'radical' => '√',
            'infinity' => '∞', 'integral' => '∫', 'approxequal' => '≈', 'lessequal' => '≤', 'greaterequal' => '≥',
            'notequal' => '≠', 'logicalnot' => '¬', 'lozenge' => '◊',
        ];

        if (isset($basicGlyphMap[$normalizedName])) {
            return $basicGlyphMap[$normalizedName];
        }

        $lowerName = strtolower($normalizedName);
        if (isset($basicGlyphMap[$lowerName])) {
            return $basicGlyphMap[$lowerName];
        }

        return '';
    }

    private function unicodeCodePointToUtf8(int $codePoint): string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            return '';
        }

        if ($codePoint < 0x80) {
            return chr($codePoint);
        }

        if ($codePoint < 0x800) {
            return chr(0xC0 | ($codePoint >> 6))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint < 0x10000) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
    }

    private function convertEncoding(string $bytes, string $from): string
    {
        if ($bytes === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            foreach ($this->resolveMbEncodingCandidates($from) as $candidate) {
                try {
                    $result = @mb_convert_encoding($bytes, 'UTF-8', $candidate);
                } catch (Throwable) {
                    $result = false;
                }

                if ($result !== false) {
                    return $result;
                }
            }
        }

        if (function_exists('iconv')) {
            foreach ($this->resolveEncodingCandidates($from) as $candidate) {
                try {
                    $result = @iconv($candidate, 'UTF-8//IGNORE', $bytes);
                } catch (Throwable) {
                    $result = false;
                }

                if ($result !== false) {
                    return $result;
                }
            }
        }

        return '';
    }

    /**
     * @return string[]
     */
    private function resolveMbEncodingCandidates(string $encoding): array
    {
        $candidates = $this->resolveEncodingCandidates($encoding);
        if (!function_exists('mb_list_encodings')) {
            return $candidates;
        }

        static $available = null;
        if ($available === null) {
            $available = [];
            foreach (mb_list_encodings() as $name) {
                $available[strtolower($name)] = $name;
            }
        }

        $resolved = [];
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($available[$key])) {
                $resolved[] = $available[$key];
            }
        }

        return $this->uniqueCaseInsensitive($resolved);
    }

    /**
     * @return string[]
     */
    private function resolveEncodingCandidates(string $encoding): array
    {
        $encoding = trim($encoding);
        if ($encoding === '') {
            return [];
        }

        $candidates = [$encoding];
        $aliases = match (strtolower($encoding)) {
            'macintosh' => ['MacRoman', 'MACINTOSH'],
            'macromanencoding' => ['MacRoman', 'MACINTOSH'],
            default => [],
        };

        foreach ($aliases as $alias) {
            $candidates[] = $alias;
        }

        return $this->uniqueCaseInsensitive($candidates);
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function uniqueCaseInsensitive(array $values): array
    {
        $unique = [];
        $seen = [];

        foreach ($values as $value) {
            $key = strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $value;
        }

        return $unique;
    }

    /**
     * @param array<string, string> $map
     * @param array{
     *     map: array<string, string>,
     *     max_code_bytes: int,
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool,
     *     has_tounicode: bool
     * }|null $fontDef
     */
    private function decodeWithFontMap(string $bytes, array $map, int $maxCodeBytes, ?array $fontDef = null): string
    {
        $hex = strtoupper(bin2hex($bytes));
        $length = strlen($hex);
        $cursor = 0;
        $out = '';
        $preferMappedOnly = $fontDef !== null
            && ($fontDef['map'] ?? []) !== []
            && (($fontDef['is_multibyte'] ?? false) || ($fontDef['has_tounicode'] ?? false));

        while ($cursor < $length) {
            $matched = false;

            for ($byteWidth = $maxCodeBytes; $byteWidth >= 1; $byteWidth--) {
                $charWidth = $byteWidth * 2;
                if ($cursor + $charWidth > $length) {
                    continue;
                }

                $code = substr($hex, $cursor, $charWidth);
                if (isset($map[$code])) {
                    $out .= $map[$code];
                    $cursor += $charWidth;
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                continue;
            }

            $stepBytes = 1;
            if (
                $fontDef !== null &&
                ($fontDef['is_multibyte'] ?? false) &&
                ($cursor + 4) <= $length
            ) {
                $stepBytes = 2;
            }

            $fallbackHex = substr($hex, $cursor, $stepBytes * 2);
            if ($fallbackHex === '') {
                break;
            }

            $fallbackBytes = @hex2bin($fallbackHex);
            if ($fallbackBytes === false) {
                break;
            }

            if ($fontDef !== null) {
                $fallbackText = $this->decodeWithFontEncodingFallback($fallbackBytes, $fontDef);
                if ($fallbackText !== '') {
                    $out .= $fallbackText;
                    $cursor += $stepBytes * 2;
                    continue;
                }
            }

            if ($stepBytes === 2 && str_starts_with($fallbackHex, '00')) {
                $out .= chr(hexdec(substr($fallbackHex, 2, 2)));
                $cursor += 4;
                continue;
            }

            $firstByte = chr(hexdec(substr($fallbackHex, 0, 2)));
            $converted = $this->convertEncoding($firstByte, 'Windows-1252');
            $out .= $converted !== '' ? $converted : $firstByte;
            $cursor += 2;
        }

        return $out;
    }

    private function hexToUtf8(string $hex): string
    {
        if ($hex === '') {
            return '';
        }

        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }

        $bytes = @hex2bin($hex);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }

        if (strlen($bytes) % 2 === 0) {
            $converted = $this->convertEncoding($bytes, 'UTF-16BE');
            if ($converted !== '') {
                return $converted;
            }
        }

        return $this->convertEncoding($bytes, 'Windows-1252');
    }

    private function sanitizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

        return $text;
    }

    /**
     * @return array<int, array{type:string,value:mixed}>
     */
    private function readArray(string $content, int &$offset): array
    {
        $items = [];
        $length = strlen($content);
        $offset++; // skip '['

        while ($offset < $length) {
            $this->skipContentWhitespaceAndComments($content, $offset);
            if ($offset >= $length) {
                break;
            }

            if ($content[$offset] === ']') {
                $offset++;
                break;
            }

            $token = $this->readContentToken($content, $offset);
            if ($token === null) {
                break;
            }

            $items[] = $token;
        }

        return $items;
    }

    private function readLiteralString(string $content, int &$offset): string
    {
        $length = strlen($content);
        $offset++; // skip '('
        $depth = 1;
        $out = '';

        while ($offset < $length) {
            $char = $content[$offset];
            $offset++;

            if ($char === '\\') {
                if ($offset >= $length) {
                    break;
                }

                $escaped = $content[$offset];
                $offset++;

                if ($escaped >= '0' && $escaped <= '7') {
                    $octal = $escaped;
                    for ($i = 0; $i < 2 && $offset < $length; $i++) {
                        $next = $content[$offset];
                        if ($next >= '0' && $next <= '7') {
                            $octal .= $next;
                            $offset++;
                        } else {
                            break;
                        }
                    }
                    // PHP historically constrained values to one byte. Make that
                    // behavior explicit to avoid PHP 8.5's out-of-range deprecation.
                    $out .= chr(octdec($octal) & 0xFF);
                    continue;
                }

                if ($escaped === "\n") {
                    continue;
                }

                if ($escaped === "\r") {
                    if ($offset < $length && $content[$offset] === "\n") {
                        $offset++;
                    }
                    continue;
                }

                $mapped = match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '(' => '(',
                    ')' => ')',
                    '\\' => '\\',
                    default => $escaped,
                };

                $out .= $mapped;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $out .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $out .= $char;
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    private function readHexString(string $content, int &$offset): string
    {
        $length = strlen($content);
        $offset++; // skip '<'
        $hex = '';

        while ($offset < $length) {
            $char = $content[$offset];
            $offset++;

            if ($char === '>') {
                break;
            }

            if ($this->isPdfWhitespace($char)) {
                continue;
            }

            $hex .= $char;
        }

        if ($hex === '') {
            return '';
        }

        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        $decoded = @hex2bin($hex);
        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }

    private function readName(string $content, int &$offset): string
    {
        $length = strlen($content);
        $offset++; // skip '/'
        $start = $offset;

        while ($offset < $length) {
            $char = $content[$offset];
            if ($this->isPdfWhitespace($char) || $this->isPdfDelimiter($char)) {
                break;
            }
            $offset++;
        }

        return substr($content, $start, $offset - $start);
    }

    private function readNumber(string $content, int &$offset): float
    {
        $length = strlen($content);
        $start = $offset;

        if ($offset < $length && ($content[$offset] === '+' || $content[$offset] === '-')) {
            $offset++;
        }

        while ($offset < $length && ctype_digit($content[$offset])) {
            $offset++;
        }

        if ($offset < $length && $content[$offset] === '.') {
            $offset++;
            while ($offset < $length && ctype_digit($content[$offset])) {
                $offset++;
            }
        }

        if ($offset < $length && ($content[$offset] === 'e' || $content[$offset] === 'E')) {
            $offset++;
            if ($offset < $length && ($content[$offset] === '+' || $content[$offset] === '-')) {
                $offset++;
            }
            while ($offset < $length && ctype_digit($content[$offset])) {
                $offset++;
            }
        }

        $raw = substr($content, $start, $offset - $start);
        if ($raw === '' || $raw === '+' || $raw === '-' || $raw === '.') {
            return 0.0;
        }

        return (float) $raw;
    }

    private function readWord(string $content, int &$offset): string
    {
        $length = strlen($content);
        $start = $offset;

        while ($offset < $length) {
            $char = $content[$offset];
            if ($this->isPdfWhitespace($char) || $this->isPdfDelimiter($char)) {
                break;
            }
            $offset++;
        }

        return substr($content, $start, $offset - $start);
    }

    private function isNumberStart(string $content, int $offset): bool
    {
        $char = $content[$offset];
        if (ctype_digit($char)) {
            return true;
        }

        if ($char === '+' || $char === '-') {
            $next = $content[$offset + 1] ?? '';
            return $next !== '' && (ctype_digit($next) || $next === '.');
        }

        if ($char === '.') {
            $next = $content[$offset + 1] ?? '';
            return $next !== '' && ctype_digit($next);
        }

        return false;
    }

    private function skipContentWhitespaceAndComments(string $content, int &$offset): void
    {
        $length = strlen($content);

        while ($offset < $length) {
            $char = $content[$offset];

            if ($this->isPdfWhitespace($char)) {
                $offset++;
                continue;
            }

            if ($char === '%') {
                while ($offset < $length && $content[$offset] !== "\n" && $content[$offset] !== "\r") {
                    $offset++;
                }
                continue;
            }

            break;
        }
    }

    private function isPdfWhitespace(string $char): bool
    {
        return $char === " " || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\x0C" || $char === "\x00";
    }

    private function isPdfDelimiter(string $char): bool
    {
        return $char === '('
            || $char === ')'
            || $char === '<'
            || $char === '>'
            || $char === '['
            || $char === ']'
            || $char === '{'
            || $char === '}'
            || $char === '/'
            || $char === '%';
    }

    private function appendNewline(string &$text): void
    {
        if ($text === '' || substr($text, -1) === "\n") {
            return;
        }

        $text .= "\n";
    }

    private function appendNewlineToArray(array &$parts, string &$lastChar): void
    {
        if ($parts === [] || $lastChar === "\n") {
            return;
        }

        $parts[] = "\n";
        $lastChar = "\n";
    }

    private function normalizeText(string $text): string
    {
        $text = $this->sanitizeText($text);
        $text = str_replace(
            ["\r\n", "\r", "\n", "\t", "\f", "\x00", "\xC2\xA0", "\xE2\x80\xAF"],
            ' ',
            $text
        );
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
