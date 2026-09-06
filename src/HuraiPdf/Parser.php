<?php

declare(strict_types=1);

namespace HuraiPdf;

use HuraiPdf\Exception\PdfParseException;

final class Parser
{
    private readonly ParserOptions $options;

    private ?ParseContext $context = null;

    private bool $active = false;

    /** @var (\Closure(int, bool): bool)|null */
    private ?\Closure $objectLoader = null;

    public function __construct(ParserOptions $options = new ParserOptions())
    {
        $this->options = $options;
    }

    private function beginOperation(): void
    {
        if ($this->active) {
            throw new \LogicException('This parser has an active operation. Close its generator or use another Parser.');
        }
        $this->context = new ParseContext();
        $this->active = true;
    }

    private function assertInputBudget(int $bytes): void
    {
        if ($bytes > $this->options->maxInputBytes) {
            throw PdfParseException::resourceLimitExceeded('PDF input exceeds maxInputBytes.');
        }
        $this->guardDeadline();
    }

    public function parseFile(string $filePath, int $fromPage = 1, ?int $toPage = null): Document
    {
        $this->beginOperation();

        try {
            $this->validatePageRange($fromPage, $toPage);

            if (!is_file($filePath) || !is_readable($filePath)) {
                throw PdfParseException::fileNotReadable($filePath);
            }

            $fileSize = filesize($filePath);
            $this->assertInputBudget($fileSize === false ? 0 : $fileSize);
            if ($fileSize !== false && $fileSize > $this->options->streamingThreshold) {
                $this->context->metrics['streaming_path'] = true;
                return $this->parseFileStreaming($filePath, $fromPage, $toPage);
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                throw PdfParseException::fileReadFailure();
            }

            return $this->parseContentInternal($content, $fromPage, $toPage);
        } finally {
            $this->context->finish();
            $this->active = false;
            $this->objectLoader = null;
        }
    }

    /**
     * Metrics from the most recent parse operation.
     *
     * @return array<string, int|float|bool|string>
     */
    public function getLastMetrics(): array
    {
        return $this->context?->metrics ?? [];
    }

    /**
     * Metadata from the most recent parse operation.
     *
     * @return array{pdf_version:string,is_encrypted:bool,warnings:string[],page_count:int}
     */
    public function getLastMetadata(): array
    {
        return $this->context?->metadata ?? [
            'pdf_version' => 'unknown',
            'is_encrypted' => false,
            'warnings' => [],
            'page_count' => 0,
        ];
    }

    /**
     * Yield pages without retaining their extracted text in a Document.
     *
     * @return \Generator<int, Page>
     */
    public function parseFilePages(string $filePath, int $fromPage = 1, ?int $toPage = null): \Generator
    {
        $this->beginOperation();

        try {
            $this->validatePageRange($fromPage, $toPage);
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw PdfParseException::fileNotReadable($filePath);
            }

            $fileSize = filesize($filePath);
            $this->assertInputBudget($fileSize === false ? 0 : $fileSize);
            $this->context->metrics['streaming_path'] = true;
            yield from $this->streamFilePagesInternal($filePath, $fromPage, $toPage);
        } finally {
            $this->context->finish();
            $this->active = false;
            $this->objectLoader = null;
        }
    }

    /**
     * Process each page through a callback and return final metadata and metrics.
     *
     * @param callable(Page):void $onPage
     * @return array{metadata:array{pdf_version:string,is_encrypted:bool,warnings:string[],page_count:int},metrics:array<string,int|float|bool|string>}
     */
    public function extractFile(
        string $filePath,
        callable $onPage,
        int $fromPage = 1,
        ?int $toPage = null
    ): array {
        foreach ($this->parseFilePages($filePath, $fromPage, $toPage) as $page) {
            $onPage($page);
        }

        return [
            'metadata' => $this->getLastMetadata(),
            'metrics' => $this->getLastMetrics(),
        ];
    }

    /**
     * Memory-efficient file parsing for large PDFs: reads only the xref table and
     * the individual object bytes actually needed, avoiding a full file_get_contents.
     */
    private function parseFileStreaming(string $filePath, int $fromPage = 1, ?int $toPage = null): Document
    {
        $pages = [];
        foreach ($this->streamFilePagesInternal($filePath, $fromPage, $toPage) as $page) {
            $pages[] = $page;
        }
        $metadata = $this->getLastMetadata();

        return new Document(
            $pages,
            $metadata['pdf_version'],
            $metadata['is_encrypted'],
            $metadata['warnings']
        );
    }

    /** @return \Generator<int, Page> */
    private function streamFilePagesInternal(string $filePath, int $fromPage, ?int $toPage): \Generator
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw PdfParseException::fileReadFailure();
        }

        try {
            yield from $this->streamHandlePagesInternal($handle, $fromPage, $toPage);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle @return \Generator<int, Page> */
    private function streamHandlePagesInternal($handle, int $fromPage, ?int $toPage): \Generator
    {
        try {
            $stat = fstat($handle);
            $fileSize = $stat !== false ? (int) $stat['size'] : 0;
            $this->assertInputBudget($fileSize);
            $header = $this->readFileRange($handle, 0, min(16, $fileSize));
            if (!str_starts_with($header, '%PDF-')) {
                throw PdfParseException::invalidHeader();
            }
            $warnings = [];
            $index = $this->readFileXRefIndex($handle, $fileSize, $warnings);
            if ($index === null || $index['root_id'] === null) {
                $document = $this->parseWholeFileFallback($handle, $fromPage, $toPage);
                foreach ($document->getPages() as $page) {
                    yield $page->getPageNumber() => $page;
                }
                return;
            }

            $objects = [];
            $this->objectLoader = function (int $id, bool $formOnly = false) use (&$objects, $index, $handle, &$warnings): bool {
                if ($formOnly && !isset($objects[$id]) && isset($index['offsets'][$id])) {
                    $entry = $index['offsets'][$id];
                    $prefix = $this->readFileRange($handle, $entry['offset'], min(4096, $entry['next'] - $entry['offset']));
                    $this->context->metrics['object_bytes_read'] += strlen($prefix);
                    if (preg_match('/\/Subtype\s*\/Image\b/', $prefix) === 1) { return false; }
                }
                return $this->loadObjectFromIndex($id, $objects, $index, $handle, $warnings);
            };
            $pageObjectIds = [];
            $ordinal = 0;
            $pagesFound = 0;

            if (!$this->loadObjectFromIndex($index['root_id'], $objects, $index, $handle, $warnings)) {
                throw PdfParseException::noObjectsFound();
            }
            $catalogBody = $objects[$index['root_id']]->body;
            if (preg_match('/\/Pages\s+(\d+)\s+\d+\s+R/', $catalogBody, $rootPagesMatch) !== 1) {
                throw PdfParseException::noPagesFound();
            }

            $this->walkPageTreeFromIndex(
                (int) $rootPagesMatch[1],
                $fromPage,
                $toPage,
                $ordinal,
                $pagesFound,
                $pageObjectIds,
                $objects,
                $index,
                $handle,
                $warnings
            );

            if ($pagesFound === 0) {
                throw PdfParseException::noPagesFound();
            }

            fseek($handle, 0);
            $header = fread($handle, 16);
            $pdfVersion = ($header !== false && preg_match('/^%PDF-([0-9.]+)/', $header, $vMatch))
                ? $vMatch[1]
                : 'unknown';

            $encrypted = $index['encrypted'];
            if ($encrypted) {
                $this->addWarning($warnings, 'Encrypted PDF detected. Extraction quality may be limited.');
            }

            $pageCount = 0;
            foreach ($pageObjectIds as $pageIndex => $pageObjectId) {
                $text = $this->extractPageText($pageObjectId, $objects, $warnings);
                $page = new Page($fromPage + $pageIndex, $pageObjectId, $this->normalizeText($text));
                $pageCount++;
                if ($pageCount === 1) {
                    $this->context->metrics['first_page_ms'] = (hrtime(true) - $this->context->startedAtNanoseconds) / 1_000_000;
                }
                $this->releasePageObjects($objects, $pageObjectId);
                $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, $pageCount);
                yield $page->getPageNumber() => $page;
            }
        } finally {
            if (isset($pdfVersion, $encrypted, $warnings, $pageCount)) {
                $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, $pageCount);
            }
        }
    }

    /** @param resource $handle */
    private function parseWholeFileFallback($handle, int $fromPage, ?int $toPage): Document
    {
        fseek($handle, 0);
        $content = stream_get_contents($handle, $this->options->maxInputBytes + 1);
        $this->assertInputBudget($content === false ? 0 : strlen($content));
        if ($content === false) {
            throw PdfParseException::fileReadFailure();
        }
        $this->context->metrics['streaming_path'] = false;
        return $this->parseRecoveredContentInternal($content, $fromPage, $toPage);
    }

    /**
     * @param resource $handle
     * @param string[] $warnings
     * @return array{
     *   offsets:array<int,array{offset:int,generation:int,next:int}>,
     *   compressed:array<int,array{stream_id:int,index:int}>,
     *   root_id:?int,
     *   encrypted:bool
     * }|null
     */
    private function readFileXRefIndex($handle, int $fileSize, array &$warnings): ?array
    {
        if ($fileSize <= 0) {
            return null;
        }

        $tailSize = min(65536, $fileSize);
        $tail = $this->readFileRange($handle, $fileSize - $tailSize, $tailSize);
        $pos = strrpos($tail, 'startxref');
        if ($pos === false || preg_match('/\s+(\d+)/', substr($tail, $pos + 9, 64), $match) !== 1) {
            return null;
        }

        $queue = [(int) $match[1]];
        $visited = [];
        $offsetEntries = [];
        $compressedEntries = [];
        $seenIds = [];
        $rootId = null;
        $encrypted = false;
        $sectionBoundaries = [];
        $allObjectBoundaries = [];

        while ($queue !== []) {
            $this->guardDeadline();
            $sectionOffset = array_shift($queue);
            if (
                isset($visited[$sectionOffset]) ||
                $sectionOffset <= 0 ||
                $sectionOffset >= $fileSize
            ) {
                continue;
            }
            $visited[$sectionOffset] = true;
            $sectionBoundaries[] = $sectionOffset;

            $prefix = ltrim($this->readFileRange($handle, $sectionOffset, min(16, $fileSize - $sectionOffset)));
            $section = str_starts_with($prefix, 'xref')
                ? $this->readTraditionalXRefSection($handle, $sectionOffset, $fileSize)
                : $this->readXRefStreamSection($handle, $sectionOffset, $fileSize, $warnings);

            if ($section === null) {
                return null;
            }

            $this->context->metrics['xref_sections']++;
            // Hybrid xref streams override placeholder free entries in their table.
            if ($section['xref_stream_offset'] !== null) {
                $hybrid = $this->readXRefStreamSection($handle, $section['xref_stream_offset'], $fileSize, $warnings);
                if ($hybrid === null) {
                    return null;
                }
                $sectionBoundaries[] = $section['xref_stream_offset'];
                $section['offsets'] = $hybrid['offsets'] + $section['offsets'];
                $section['compressed'] = $hybrid['compressed'] + $section['compressed'];
                $section['free'] = array_diff_key($section['free'], $hybrid['offsets'], $hybrid['compressed']) + $hybrid['free'];
                $section['xref_stream_offset'] = null;
                $this->context->metrics['xref_sections']++;
            }
            foreach ($section['offsets'] as $objectId => $entry) {
                $allObjectBoundaries[$entry['offset']] = $entry['offset'];
                if (!isset($seenIds[$objectId])) {
                    $offsetEntries[$objectId] = $entry;
                    $seenIds[$objectId] = true;
                }
            }
            foreach ($section['compressed'] as $objectId => $entry) {
                if (!isset($seenIds[$objectId])) {
                    $compressedEntries[$objectId] = $entry;
                    $seenIds[$objectId] = true;
                }
            }
            foreach ($section['free'] as $objectId => $_) {
                $seenIds[$objectId] = true;
            }
            $this->assertObjectBudget(count($seenIds) - (isset($seenIds[0]) ? 1 : 0));
            if (count($visited) > $this->options->maxObjects || count($allObjectBoundaries) > $this->options->maxObjects) {
                throw PdfParseException::resourceLimitExceeded('Cross-reference history exceeds maxObjects.');
            }

            $rootId ??= $section['root_id'];
            $encrypted = $encrypted || $section['encrypted'];
            if ($section['xref_stream_offset'] !== null) {
                $queue[] = $section['xref_stream_offset'];
            }
            if ($section['prev'] !== null) {
                $queue[] = $section['prev'];
            }
        }

        if ($offsetEntries === [] && $compressedEntries === []) {
            return null;
        }

        $boundaries = array_merge(
            $allObjectBoundaries,
            $sectionBoundaries,
            [$fileSize]
        );
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries, SORT_NUMERIC);
        $nextByOffset = [];
        $boundaryCount = count($boundaries);
        for ($i = 0; $i + 1 < $boundaryCount; $i++) {
            $nextByOffset[$boundaries[$i]] = $boundaries[$i + 1];
        }

        foreach ($offsetEntries as &$entry) {
            $entry['next'] = $nextByOffset[$entry['offset']] ?? $fileSize;
        }
        unset($entry);

        $indexedCount = count($offsetEntries) + count($compressedEntries);
        $this->assertObjectBudget($indexedCount);
        $this->context->metrics['objects_indexed'] = $indexedCount;

        return [
            'offsets' => $offsetEntries,
            'compressed' => $compressedEntries,
            'root_id' => $rootId,
            'encrypted' => $encrypted,
        ];
    }

    /**
     * @param resource $handle
     * @return array{offsets:array<int,array{offset:int,generation:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool,prev:?int,xref_stream_offset:?int}|null
     */
    private function readPdfLine($handle, string &$buffer): string|false
    {
        while (true) {
            $end = strcspn($buffer, "\r\n");
            if ($end < strlen($buffer)) {
                if ($buffer[$end] === "\r" && $end + 1 === strlen($buffer) && !feof($handle)) {
                    $part = fread($handle, 4096);
                    if ($part !== false) { $buffer .= $part; }
                }
                $length = $end + (($buffer[$end] === "\r" && ($buffer[$end + 1] ?? '') === "\n") ? 2 : 1);
                $line = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);
                return $line;
            }
            if (strlen($buffer) >= 65536) { return false; }
            $this->guardDeadline();
            $part = fread($handle, 4096);
            if ($part === false || $part === '') {
                $line = $buffer;
                $buffer = '';
                return $line === '' ? false : $line;
            }
            $buffer .= $part;
        }
    }

    private function readTraditionalXRefSection($handle, int $offset, int $fileSize): ?array
    {
        fseek($handle, $offset);
        $lineBuffer = "";
        $firstLine = $this->readPdfLine($handle, $lineBuffer);
        if ($firstLine === false || trim($firstLine) !== 'xref') {
            return null;
        }

        $entries = [];
        $free = [];
        $trailerText = '';
        while (($line = $this->readPdfLine($handle, $lineBuffer)) !== false) {
            $this->guardDeadline();
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, 'trailer')) {
                $trailerText = substr($trimmed, 7);
                while (
                    $this->extractFirstDictionary($trailerText) === null &&
                    strlen($trailerText) < 1_048_576 &&
                    ($nextLine = $this->readPdfLine($handle, $lineBuffer)) !== false
                ) {
                    $trailerText .= "\n" . $nextLine;
                }
                break;
            }

            if (preg_match('/^(\d+)\s+(\d+)$/', $trimmed, $subsection) !== 1) {
                return null;
            }
            $startId = (int) $subsection[1];
            $count = (int) $subsection[2];
            $this->assertObjectBudget(count($entries) + count($free) + max(0, $count - ($startId === 0 ? 1 : 0)));
            for ($i = 0; $i < $count; $i++) {
                if (($i & 255) === 0) { $this->guardDeadline(); }
                $entryLine = $this->readPdfLine($handle, $lineBuffer);
                if ($entryLine === false) {
                    return null;
                }
                if (preg_match('/^(\d{10})\s+(\d{5})\s+([nf])/', $entryLine, $entryMatch) !== 1) {
                    return null;
                }
                if ($entryMatch[3] !== 'n') {
                    $free[$startId + $i] = true;
                    continue;
                }
                $objectOffset = (int) $entryMatch[1];
                if ($objectOffset > 0 && $objectOffset < $fileSize) {
                    $entries[$startId + $i] = [
                        'offset' => $objectOffset,
                        'generation' => (int) $entryMatch[2],
                    ];
                    $this->assertObjectBudget(count($entries));
                }
            }
        }

        $dictionary = $this->extractFirstDictionary($trailerText) ?? '';
        return [
            'free' => $free,
            'offsets' => $entries,
            'compressed' => [],
            'root_id' => $this->extractReferenceId($dictionary, 'Root'),
            'encrypted' => preg_match('/\/Encrypt\b/', $dictionary) === 1,
            'prev' => $this->extractIntegerValue($dictionary, 'Prev'),
            'xref_stream_offset' => $this->extractIntegerValue($dictionary, 'XRefStm'),
        ];
    }

    /**
     * @param resource $handle
     * @param string[] $warnings
     * @return array{offsets:array<int,array{offset:int,generation:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool,prev:?int,xref_stream_offset:?int}|null
     */
    private function readXRefStreamSection($handle, int $offset, int $fileSize, array &$warnings): ?array
    {
        $rawObject = $this->readObjectGrowing($handle, $offset, $fileSize);
        if ($rawObject === null || preg_match('/^\s*(\d+)\s+(\d+)\s+obj\b/', $rawObject, $header) !== 1) {
            return null;
        }
        $bodyStart = strlen($header[0]);
        $endOffset = $this->locateEndObjOffset($rawObject, $bodyStart);
        if ($endOffset === null) {
            return null;
        }
        $body = substr($rawObject, $bodyStart, $endOffset - $bodyStart);
        $streamInfo = $this->extractStreamInfoFromObjectBody($body);
        if ($streamInfo === null) {
            return null;
        }
        $decoded = $this->decodeStream($streamInfo['dictionary'], $streamInfo['stream'], $warnings, 0);
        if ($decoded === '') {
            return null;
        }

        $dictionary = $streamInfo['dictionary'];
        if (preg_match('/\/W\s*\[([\s\d]+)\]/', $dictionary, $widthMatch) !== 1) {
            return null;
        }
        $widths = array_map('intval', preg_split('/\s+/', trim($widthMatch[1])) ?: []);
        if (count($widths) !== 3 || max($widths) > 8) {
            return null;
        }
        [$w0, $w1, $w2] = $widths;
        $entrySize = $w0 + $w1 + $w2;
        if ($entrySize <= 0) {
            return null;
        }

        $ranges = [];
        if (preg_match('/\/Index\s*\[(.*?)\]/s', $dictionary, $indexMatch) === 1) {
            $tokens = preg_split('/\s+/', trim($indexMatch[1])) ?: [];
            for ($i = 0, $count = count($tokens); $i + 1 < $count; $i += 2) {
                $ranges[] = [(int) $tokens[$i], (int) $tokens[$i + 1]];
            }
        }
        if ($ranges === []) {
            $ranges[] = [0, $this->extractIntegerValue($dictionary, 'Size') ?? 0];
        }

        $offsetEntries = [];
        $compressedEntries = [];
        $free = [];
        $dataOffset = 0;
        $dataLength = strlen($decoded);
        foreach ($ranges as [$startId, $rangeCount]) {
            $this->assertObjectBudget(count($offsetEntries) + count($compressedEntries) + count($free) + max(0, $rangeCount - ($startId === 0 ? 1 : 0)));
            for ($i = 0; $i < $rangeCount; $i++) {
                if (($i & 255) === 0) { $this->guardDeadline(); }
                if ($dataOffset + $entrySize > $dataLength) {
                    return null;
                }
                $type = $this->readBigEndianField($decoded, $dataOffset, $w0);
                if ($w0 === 0) {
                    $type = 1;
                }
                $field1 = $this->readBigEndianField($decoded, $dataOffset, $w1);
                $field2 = $this->readBigEndianField($decoded, $dataOffset, $w2);
                $objectId = $startId + $i;
                if ($type === 0) {
                    $free[$objectId] = true;
                } elseif ($type === 1 && $field1 > 0 && $field1 < $fileSize) {
                    $offsetEntries[$objectId] = ['offset' => $field1, 'generation' => $field2];
                } elseif ($type === 2 && $field1 > 0) {
                    $compressedEntries[$objectId] = ['stream_id' => $field1, 'index' => $field2];
                }
                $this->assertObjectBudget(count($offsetEntries) + count($compressedEntries));
            }
        }

        return [
            'free' => $free,
            'offsets' => $offsetEntries,
            'compressed' => $compressedEntries,
            'root_id' => $this->extractReferenceId($dictionary, 'Root'),
            'encrypted' => preg_match('/\/Encrypt\b/', $dictionary) === 1,
            'prev' => $this->extractIntegerValue($dictionary, 'Prev'),
            'xref_stream_offset' => null,
        ];
    }

    private function readBigEndianField(string $data, int &$offset, int $width): int
    {
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($data[$offset++]);
        }
        return $value;
    }

    private function extractReferenceId(string $dictionary, string $key): ?int
    {
        return preg_match('/\/' . preg_quote($key, '/') . '\s+(\d+)\s+\d+\s+R\b/', $dictionary, $match) === 1
            ? (int) $match[1]
            : null;
    }

    private function extractIntegerValue(string $dictionary, string $key): ?int
    {
        return preg_match('/\/' . preg_quote($key, '/') . '\s+(\d+)\b/', $dictionary, $match) === 1
            ? (int) $match[1]
            : null;
    }

    /** @param resource $handle */
    private function readFileRange($handle, int $offset, int $length): string
    {
        if ($length <= 0 || fseek($handle, $offset) !== 0) {
            return '';
        }
        $result = '';
        while (strlen($result) < $length) {
            $this->guardDeadline();
            $part = fread($handle, min($this->options->objectChunkSize, $length - strlen($result)));
            if ($part === false || $part === '') {
                break;
            }
            $result .= $part;
        }
        return $result;
    }

    /** @param resource $handle */
    private function readObjectGrowing($handle, int $offset, int $fileSize): ?string
    {
        if (fseek($handle, $offset) !== 0) {
            return null;
        }
        $chunk = '';
        $chunkSize = max(4096, $this->options->objectChunkSize);
        $maximum = min($this->options->maxObjectBytes, $fileSize - $offset);
        while (strlen($chunk) < $maximum) {
            $this->guardDeadline();
            $part = fread($handle, min($chunkSize, $maximum - strlen($chunk)));
            if ($part === false || $part === '') {
                break;
            }
            $chunk .= $part;
            if (preg_match('/^\s*\d+\s+\d+\s+obj\b/', $chunk, $header) === 1) {
                $bodyStart = strlen($header[0]);
                $streamToken = strpos($chunk, 'stream', $bodyStart);
                if ($streamToken !== false) {
                    $dictionary = substr($chunk, $bodyStart, $streamToken - $bodyStart);
                    $declaredLength = $this->extractDirectStreamLength($dictionary);
                    if ($declaredLength !== null) {
                        $streamStart = $streamToken + 6;
                        if (($chunk[$streamStart] ?? '') === "\r") {
                            $streamStart++;
                        }
                        if (($chunk[$streamStart] ?? '') === "\n") {
                            $streamStart++;
                        }
                        $expectedEnd = $streamStart + $declaredLength;
                        if (strlen($chunk) <= $expectedEnd) {
                            continue;
                        }
                        $endStream = strpos($chunk, 'endstream', $expectedEnd);
                        $endObject = $endStream === false ? false : strpos($chunk, 'endobj', $endStream + 9);
                        if ($endObject !== false) {
                            return $chunk;
                        }
                        continue;
                    }
                }
                if ($this->locateEndObjOffset($chunk, $bodyStart) !== null) {
                    return $chunk;
                }
            }
        }
        if (strlen($chunk) >= $this->options->maxObjectBytes) {
            throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
        }
        return $chunk === '' ? null : $chunk;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param array{offsets:array<int,array{offset:int,generation:int,next:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool} $index
     * @param resource $handle
     * @param string[] $warnings
     */
    private function loadObjectFromIndex(
        int $objectId,
        array &$objects,
        array $index,
        $handle,
        array &$warnings,
        int $depth = 0
    ): bool
    {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Object reference nesting exceeds maxRecursionDepth.');
        }
        $this->guardDeadline();
        if (isset($objects[$objectId])) {
            return true;
        }

        if (isset($index['compressed'][$objectId])) {
            $streamId = $index['compressed'][$objectId]['stream_id'];
            if (!$this->loadObjectFromIndex($streamId, $objects, $index, $handle, $warnings, $depth + 1)) {
                return false;
            }
            unset($this->context->expandedObjectStreams[$streamId]);
            $this->expandObjectStreams($objects, $warnings, $index);
            return isset($objects[$objectId]);
        }

        if (!isset($index['offsets'][$objectId])) {
            return false;
        }
        $entry = $index['offsets'][$objectId];
        $length = $entry['next'] - $entry['offset'];
        if ($length <= 0) { return false; }
        if ($length > $this->options->maxObjectBytes) {
            throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
        }
        $raw = $this->readFileRange($handle, $entry['offset'], $length);
        $this->context->metrics['object_bytes_read'] += strlen($raw);
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+obj\b/', $raw, $header) !== 1) {
            return false;
        }
        if ((int) $header[1] !== $objectId || (int) $header[2] !== $entry['generation']) {
            $this->addWarning($warnings, 'Cross-reference object identity mismatch for object ' . $objectId . '.');
            return false;
        }
        $bodyStart = strlen($header[0]);
        $dictionary = $this->extractFirstDictionary(substr($raw, $bodyStart)) ?? '';
        if (preg_match('/\/Length\s+(\d+)\s+\d+\s+R\b/', $dictionary, $lengthRef) === 1) {
            $this->loadObjectFromIndex((int) $lengthRef[1], $objects, $index, $handle, $warnings, $depth + 1);
        }
        $endOffset = $this->locateEndObjOffset($raw, $bodyStart, $objects);
        if ($endOffset === null) {
            return false;
        }
        $objects[$objectId] = new PdfObject(
            $objectId,
            (int) $header[2],
            substr($raw, $bodyStart, $endOffset - $bodyStart),
            $entry['offset'],
            false
        );
        $this->context->metrics['objects_loaded']++;
        return true;
    }

    /**
     * @param int[] $selected
     * @param array<int, PdfObject> $objects
     * @param array{offsets:array<int,array{offset:int,generation:int,next:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool} $index
     * @param resource $handle
     * @param string[] $warnings
     */
    private function walkPageTreeFromIndex(
        int $objectId,
        int $fromPage,
        ?int $toPage,
        int &$ordinal,
        int &$pagesFound,
        array &$selected,
        array &$objects,
        array $index,
        $handle,
        array &$warnings,
        int $depth = 0
    ): void {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Page tree exceeds maxRecursionDepth.');
        }
        $this->guardDeadline();
        if ($toPage !== null && $ordinal >= $toPage) {
            return;
        }
        if (!$this->loadObjectFromIndex($objectId, $objects, $index, $handle, $warnings)) {
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
            if ($this->loadObjectFromIndex($childId, $objects, $index, $handle, $warnings)) {
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

    public function parseContent(string $content, int $fromPage = 1, ?int $toPage = null): Document
    {
        $this->beginOperation();

        try {
            $this->validatePageRange($fromPage, $toPage);
            return $this->parseContentInternal($content, $fromPage, $toPage);
        } finally {
            $this->context->finish();
            $this->active = false;
            $this->objectLoader = null;
        }
    }

    private function parseContentInternal(string $content, int $fromPage, ?int $toPage): Document
    {
        $this->assertInputBudget(strlen($content));
        $handle = fopen('php://memory', 'w+b');
        if ($handle === false) {
            throw PdfParseException::fileReadFailure();
        }
        try {
            if (fwrite($handle, $content) !== strlen($content)) {
                throw PdfParseException::fileReadFailure();
            }
            $pages = iterator_to_array($this->streamHandlePagesInternal($handle, $fromPage, $toPage), false);
            $metadata = $this->getLastMetadata();
            return new Document($pages, $metadata['pdf_version'], $metadata['is_encrypted'], $metadata['warnings']);
        } finally {
            fclose($handle);
        }
    }

    private function parseRecoveredContentInternal(string $content, int $fromPage, ?int $toPage): Document
    {
        $this->validatePageRange($fromPage, $toPage);

        if (!str_starts_with($content, '%PDF-')) {
            throw PdfParseException::invalidHeader();
        }

        $warnings = [];
        $this->addWarning($warnings, 'Cross-reference index unavailable; using bounded full-file recovery.');
        $this->context->metrics['recovery_path'] = true;
        $pdfVersion = $this->extractPdfVersion($content);
        $objects = $this->collectIndirectObjects($content);
        $this->assertObjectBudget(count($objects));
        if ($this->context !== null) {
            $this->context->metrics['objects_indexed'] = count($objects);
            $this->context->metrics['objects_loaded'] = count($objects);
            $this->context->metrics['object_bytes_read'] = strlen($content);
        }

        if ($objects === []) {
            throw PdfParseException::noObjectsFound();
        }

        $this->expandObjectStreams($objects, $warnings);

        $resolutionLimit = $toPage ?? ($fromPage > PHP_INT_MAX - $this->options->maxPages ? PHP_INT_MAX : $fromPage + $this->options->maxPages);
        $pageObjectIds = $this->resolvePageObjectIds($objects, $resolutionLimit);
        if ($pageObjectIds === []) {
            throw PdfParseException::noPagesFound();
        }

        $pageObjectIds = array_slice($pageObjectIds, $fromPage - 1, $toPage !== null ? $toPage - $fromPage + 1 : null);
        if (count($pageObjectIds) > $this->options->maxPages) {
            throw PdfParseException::resourceLimitExceeded('Parsed page count exceeds maxPages.');
        }

        $pages = [];
        foreach ($pageObjectIds as $index => $pageObjectId) {
            $this->guardDeadline();
            $text = $this->extractPageText($pageObjectId, $objects, $warnings);
            $pages[] = new Page($fromPage + $index, $pageObjectId, $this->normalizeText($text));
        }

        $trailerTail = substr($content, max(0, strlen($content) - 4096));
        $encrypted = str_contains($trailerTail, '/Encrypt');
        if ($encrypted) {
            $this->addWarning($warnings, 'Encrypted PDF detected. Extraction quality may be limited.');
        }

        $this->recordParseMetadata($pdfVersion, $encrypted, $warnings, count($pages));

        return new Document($pages, $pdfVersion, $encrypted, $warnings);
    }

    /** @param string[] $warnings */
    private function recordParseMetadata(string $pdfVersion, bool $encrypted, array $warnings, int $pageCount): void
    {
        $this->context->metadata = [
            'pdf_version' => $pdfVersion,
            'is_encrypted' => $encrypted,
            'warnings' => $warnings,
            'page_count' => $pageCount,
        ];
    }

    private function validatePageRange(int $fromPage, ?int $toPage): void
    {
        if ($fromPage < 1) {
            throw PdfParseException::invalidPageRange('fromPage must be 1 or greater.');
        }
        if ($toPage !== null && $toPage < $fromPage) {
            throw PdfParseException::invalidPageRange('toPage must be greater than or equal to fromPage.');
        }
        if ($toPage !== null && ($toPage - $fromPage + 1) > $this->options->maxPages) {
            throw PdfParseException::resourceLimitExceeded('Requested page range exceeds maxPages.');
        }

    }

    private function ensureObject(int $id, array &$objects, bool $formOnly = false): bool
    {
        return isset($objects[$id]) || ($this->objectLoader !== null && ($this->objectLoader)($id, $formOnly));
    }

    private function releasePageObjects(array &$objects, int $pageId): void
    {
        unset($objects[$pageId], $this->context->resourceBodiesCache[$pageId]);
        $bytes = 0;
        foreach ($objects as $id => $object) {
            if (str_contains($object->body, 'stream')) {
                unset($objects[$id], $this->context->expandedObjectStreams[$id]);
                continue;
            }
            $bytes += strlen($object->body) + 256;
        }
        $bytes += $this->context->cmapAllocationBytes;
        foreach (['decodedStreamCache', 'formTextCache', 'resourceBodiesCache', 'resourceFontMapsCache', 'resourceXObjectMapsCache'] as $cache) {
            foreach ($this->context->$cache as $value) {
                $bytes += $this->estimateCacheBytes($value);
            }
        }
        if ($bytes > $this->options->maxCacheBytes) {
            $objects = [];
            foreach (['fontMapCache', 'decodedStreamCache', 'resourceBodiesCache', 'expandedObjectStreams', 'formTextCache', 'resourceFontMapsCache', 'resourceXObjectMapsCache'] as $cache) {
                $this->context->$cache = [];
            }
            $this->context->cmapAllocationBytes = 0;
            $this->context->metrics['cache_evictions']++;
            $bytes = 0;
        }
        $this->context->metrics['cache_bytes'] = $bytes;
    }

    private function cacheStringResult(string $cache, int $id, string $value): void
    {
        if (strlen($value) + 128 > $this->options->maxCacheBytes) { return; }
        $bytes = $this->context->cmapAllocationBytes + strlen($value) + 128;
        foreach (['decodedStreamCache', 'formTextCache'] as $name) {
            foreach ($this->context->$name as $key => $entry) {
                if ($name !== $cache || $key !== $id) { $bytes += strlen($entry) + 128; }
            }
        }
        if ($bytes > $this->options->maxCacheBytes) {
            $this->context->decodedStreamCache = [];
            $this->context->formTextCache = [];
            $this->context->metrics['cache_evictions']++;
        }
        if ($this->context->cmapAllocationBytes + strlen($value) + 128 <= $this->options->maxCacheBytes) {
            $this->context->{$cache}[$id] = $value;
        }
    }

    private function estimateCacheBytes(mixed $value): int
    {
        if (is_string($value)) { return strlen($value) + 64; }
        if (!is_array($value)) { return 32; }
        $bytes = 128;
        foreach ($value as $key => $child) {
            // Font maps are already charged once in cmapAllocationBytes.
            $bytes += 64 + (is_string($key) ? strlen($key) : 0);
            if ($key !== 'map') { $bytes += $this->estimateCacheBytes($child); }
        }
        return $bytes;
    }

    private function appendBoundedText(string &$out, string $text): void
    {
        if (strlen($text) > $this->options->maxExtractedTextBytes - strlen($out)) {
            throw PdfParseException::resourceLimitExceeded('Extracted text exceeds maxExtractedTextBytes.');
        }
        $out .= $text;
    }

    private function appendTextPart(array &$parts, string $text): void
    {
        $total = $this->context->metrics['generated_text_bytes'] + strlen($text);
        if ($total > $this->options->maxExtractedTextBytes) {
            throw PdfParseException::resourceLimitExceeded('Generated text exceeds maxExtractedTextBytes.');
        }
        $this->context->metrics['generated_text_bytes'] = $total;
        $parts[] = $text;
    }

    private function accountCMapEntry(int $bytes): void
    {
        $this->guardDeadline();
        if (++$this->context->metrics['cmap_entries'] > $this->options->maxCMapEntries) {
            throw PdfParseException::resourceLimitExceeded('CMap expansion exceeds maxCMapEntries.');
        }
        $this->context->cmapAllocationBytes += $bytes + 128;
        if ($this->context->cmapAllocationBytes > $this->options->maxCacheBytes) {
            throw PdfParseException::resourceLimitExceeded('CMap allocation exceeds maxCacheBytes.');
        }
    }

    private function accountIntermediateBytes(int $bytes): void
    {
        $this->context->intermediateDecodedBytes += $bytes;
        $this->context->metrics['decoded_work_bytes'] = $this->context->intermediateDecodedBytes;
        if ($this->context->intermediateDecodedBytes > $this->options->maxDecodedBytesTotal) {
            throw PdfParseException::resourceLimitExceeded('Intermediate decoded data exceeds maxDecodedBytesTotal.');
        }
    }

    private function guardDeadline(): void
    {
        if ($this->options->deadlineSeconds === null) {
            return;
        }
        $elapsed = (hrtime(true) - $this->context->startedAtNanoseconds) / 1_000_000_000;
        if ($elapsed > $this->options->deadlineSeconds) {
            throw PdfParseException::resourceLimitExceeded('PDF parsing deadline exceeded.');
        }
    }

    private function assertObjectBudget(int $objectCount): void
    {
        if ($objectCount > $this->options->maxObjects) {
            throw PdfParseException::resourceLimitExceeded('PDF object count exceeds maxObjects.');
        }
    }

    private function accountDecodedBytes(int $bytes): void
    {
        if ($bytes > $this->options->maxStreamBytes) {
            throw PdfParseException::resourceLimitExceeded('Decoded PDF stream exceeds maxStreamBytes.');
        }
        $total = (int) $this->context->metrics['decoded_bytes'] + $bytes;
        if ($total > $this->options->maxDecodedBytesTotal) {
            throw PdfParseException::resourceLimitExceeded('Decoded PDF data exceeds maxDecodedBytesTotal.');
        }
        $this->context->metrics['decoded_streams']++;
        $this->context->metrics['decoded_bytes'] = $total;
    }

    /** @param string[] $warnings */
    private function addWarning(array &$warnings, string $warning): void
    {
        if (count($warnings) < $this->options->maxWarnings) {
            $warnings[] = $warning;
            return;
        }
        if (($warnings[$this->options->maxWarnings - 1] ?? '') !== 'Additional warnings truncated.') {
            $warnings[$this->options->maxWarnings - 1] = 'Additional warnings truncated.';
        }
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
        $cursor = 0;
        while (preg_match('/(?:^|[\r\n])(\d+)\s+(\d+)\s+obj\b/', $content, $match, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $this->guardDeadline();
            $id = (int) $match[1][0];
            $bodyStart = $match[0][1] + strlen($match[0][0]);
            $end = $this->locateEndObjOffset($content, $bodyStart);
            if ($end === null) {
                break;
            }
            $cursor = $end + 6;
            if ($id <= 0) {
                continue;
            }
            $this->assertObjectBudget(count($objects) + (isset($objects[$id]) ? 0 : 1));
            if ($end - $bodyStart > $this->options->maxObjectBytes) {
                throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
            }
            $objects[$id] = new PdfObject($id, (int) $match[2][0], substr($content, $bodyStart, $end - $bodyStart), $match[1][1], false);
        }
        return $objects;
    }

    private function locateEndObjOffset(string $content, int $searchOffset, array &$objects = []): ?int
    {
        $cursor = $searchOffset;
        $length = strlen($content);
        $dictionary = '';
        while ($cursor < $length) {
            $this->guardDeadline();
            $this->skipContentWhitespaceAndComments($content, $cursor);
            if (substr($content, $cursor, 6) === 'endobj' && $this->tokenEndsAt($content, $cursor + 6)) {
                return $cursor;
            }
            if (substr($content, $cursor, 6) === 'stream' && $this->tokenEndsAt($content, $cursor + 6)) {
                $cursor += 6;
                if (($content[$cursor] ?? '') === "\r") { $cursor++; }
                if (($content[$cursor] ?? '') === "\n") { $cursor++; }
                $start = $cursor;
                $declared = $this->resolveStreamLength($dictionary, $objects);
                if ($declared !== null) {
                    if ($declared > $this->options->maxStreamBytes) {
                        throw PdfParseException::resourceLimitExceeded('Compressed PDF stream exceeds maxStreamBytes.');
                    }
                    $cursor = $start + $declared;
                    $this->skipContentWhitespaceAndComments($content, $cursor);
                    if (substr($content, $cursor, 9) === 'endstream' && $this->tokenEndsAt($content, $cursor + 9)) {
                        $cursor += 9;
                        continue;
                    }
                }
                // Bounded recovery for missing/incorrect Length. Require a real object terminator.
                if (preg_match('/(?<![A-Za-z0-9_])endstream\s+endobj\b/', $content, $match, PREG_OFFSET_CAPTURE, $start) !== 1) {
                    return null;
                }
                if ($match[0][1] - $start > $this->options->maxStreamBytes) {
                    throw PdfParseException::resourceLimitExceeded('Recovered stream exceeds maxStreamBytes.');
                }
                $cursor = $match[0][1] + 9;
                continue;
            }
            $start = $cursor;
            $item = $this->readPdfArrayItem($content, $cursor);
            if (str_starts_with($item, '<<')) { $dictionary = $item; }
            if ($cursor <= $start) { return null; }
            if ($cursor - $searchOffset > $this->options->maxObjectBytes) {
                throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
            }
        }
        return null;
    }

    private function tokenEndsAt(string $text, int $offset): bool
    {
        return !isset($text[$offset]) || $this->isPdfWhitespace($text[$offset]) || $this->isPdfDelimiter($text[$offset]);
    }

    private function extractDirectStreamLength(string $dictionary): ?int
    {
        if (preg_match('/\/Length\s+\d+\s+\d+\s+R\b/', $dictionary) === 1) { return null; }
        return $this->extractIntegerValue($dictionary, 'Length');
    }

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     */
    private function expandObjectStreams(array &$objects, array &$warnings, ?array $index = null): void
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
            $this->guardDeadline();
            if (!preg_match('/\/Type\s*\/ObjStm\b/', $containerObject->body)) {
                continue;
            }
            if (isset($this->context->expandedObjectStreams[$containerObject->id])) {
                continue;
            }
            $this->context->expandedObjectStreams[$containerObject->id] = true;

            $streamInfo = $this->extractStreamInfoFromObjectBody($containerObject->body, $objects);
            if ($streamInfo === null) {
                $this->addWarning($warnings, 'Object stream ' . $containerObject->id . ' could not be read.');
                continue;
            }

            $decoded = $this->decodeStream(
                $streamInfo['dictionary'],
                $streamInfo['stream'],
                $warnings,
                $containerObject->id
            );

            if ($decoded === '') {
                $this->addWarning($warnings, 'Object stream ' . $containerObject->id . ' produced empty decoded data.');
                continue;
            }

            if (
                preg_match('/\/N\s+(\d+)/', $streamInfo['dictionary'], $nMatch) !== 1 ||
                preg_match('/\/First\s+(\d+)/', $streamInfo['dictionary'], $fMatch) !== 1
            ) {
                $this->addWarning($warnings, 'Object stream ' . $containerObject->id . ' missing /N or /First.');
                continue;
            }

            $count = (int) $nMatch[1];
            $this->assertObjectBudget($count);
            $first = (int) $fMatch[1];

            if ($first < 0 || $first > strlen($decoded)) {
                $this->addWarning($warnings, 'Invalid object stream header length.');
                continue;
            }
            $header = substr($decoded, 0, $first);
            $objectData = substr($decoded, $first);
            $tokens = [];
            $headerOffset = 0;
            for ($tokenIndex = 0; $tokenIndex < $count * 2; $tokenIndex++) {
                if (($tokenIndex & 255) === 0) { $this->guardDeadline(); }
                if (preg_match('/\G\s*(\d+)/', $header, $token, 0, $headerOffset) !== 1) { break; }
                $tokens[] = $token[1];
                $headerOffset += strlen($token[0]);
            }

            if (count($tokens) < $count * 2) {
                $this->addWarning($warnings, 'Object stream ' . $containerObject->id . ' index is shorter than expected.');
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                if (($i & 255) === 0) {
                    $this->guardDeadline();
                }
                $objectId = (int) $tokens[$i * 2];
                if ($index !== null && (
                    ($index['compressed'][$objectId]['stream_id'] ?? null) !== $containerObject->id ||
                    ($index['compressed'][$objectId]['index'] ?? null) !== $i
                )) {
                    continue;
                }
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
                $this->assertObjectBudget(count($objects));
                $this->context->metrics['objects_loaded']++;
            }
        }
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return int[]
     */
    private function resolvePageObjectIds(array &$objects, ?int $toPage = null): array
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
        $this->guardDeadline();
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

    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     */
    private function extractPageText(int $pageObjectId, array &$objects, array &$warnings): string
    {
        if (!$this->ensureObject($pageObjectId, $objects)) {
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
        $state = ['inside' => false, 'font' => null, 'stack' => [], 'operands' => []];
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
                [],
                $state
            );
        }

        return trim(implode('', $streamTexts));
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return array<string, int>
     */
    private function buildPageXObjectMap(int $pageObjectId, string $pageBody, array &$objects): array
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
    private function resolvePageContentObjectIds(string $pageBody, array &$objects): array
    {
        $ids = [];
        $visited = [];
        $referenceCount = 0;

        if (preg_match('/\/Contents\s*\[(.*?)\]/s', $pageBody, $arrayMatch) === 1) {
            $offset = 0;
            while (preg_match('/(\d+)\s+\d+\s+R\b/', $arrayMatch[1], $ref, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $ref[0][1] + strlen($ref[0][0]);
                foreach ($this->resolveContentReferenceObjectIds((int) $ref[1][0], $objects, $visited, $referenceCount) as $streamId) {
                    $ids[] = $streamId;
                }
            }

            return $ids;
        }

        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $pageBody, $singleMatch) === 1) {
            $refId = (int) $singleMatch[1];
            foreach ($this->resolveContentReferenceObjectIds($refId, $objects, $visited, $referenceCount) as $streamId) {
                $ids[] = $streamId;
            }
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
        $this->guardDeadline();
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

        if (!$this->ensureObject($objectId, $objects)) {
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
            $offset = 0;
            while (preg_match('/(\d+)\s+\d+\s+R\b/', $arrayMatch[1], $ref, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $ref[0][1] + strlen($ref[0][0]);
                foreach ($this->resolveContentReferenceObjectIds((int) $ref[1][0], $objects, $visited, $referenceCount, $depth + 1) as $streamId) {
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
    private function buildPageFontMaps(int $pageObjectId, string $pageBody, array &$objects, array &$warnings): array
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
    private function buildFontMapsFromResourceBodies(array $resourceBodies, array &$objects, array &$warnings): array
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
                    $resourceName = $this->decodePdfNameEscapes($fontRef[1]);
                    $fontObjectId = (int) $fontRef[2];

                    if (!$this->ensureObject($fontObjectId, $objects)) {
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
            return $cache[$fontObjectId] = $mapData;
        }

        $toUnicodeObjectId = (int) $toUnicodeMatch[1];
        if (!$this->ensureObject($toUnicodeObjectId, $objects)) {
            return $cache[$fontObjectId] = $mapData;
        }

        $streamInfo = $this->extractStreamInfoFromObjectBody($objects[$toUnicodeObjectId]->body, $objects);
        if ($streamInfo === null) {
            return $cache[$fontObjectId] = $mapData;
        }

        $decodedCMap = $this->decodeStream(
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
            $this->addWarning($warnings, 'ToUnicode CMap on object ' . $fontObjectId . ' exceeds configured size — truncated for safety.');
            $decodedCMap = substr($decodedCMap, 0, $this->options->maxCMapSize);
        }

        $toUnicodeMapData = $this->parseToUnicodeCMap($decodedCMap);
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
        while (!isset($visited[$currentPageId]) && $this->ensureObject($currentPageId, $objects)) {
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
    private function extractResourceDictionaryBodiesFromObjectBody(string $objectBody, array &$objects): array
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
        if (isset($visited[$objectId]) || !$this->ensureObject($objectId, $objects)) {
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
    private function resolveIndirectObjectToken(int $objectId, array &$objects, array $visited, int $depth = 0): string
    {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Indirect object nesting exceeds maxRecursionDepth.');
        }
        if (isset($visited[$objectId]) || !$this->ensureObject($objectId, $objects)) {
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
    private function extractXObjectMapFromResourceDictionary(string $resourceBody, array &$objects): array
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
                $map[$this->decodePdfNameEscapes($ref[1])] = (int) $ref[2];
            }
        }

        return $this->context->resourceXObjectMapsCache[$cacheKey] = $map;
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return array{
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool
     * }
     */
    private function parseFontEncodingData(int $fontObjectId, string $fontBody, array &$objects): array
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
        $currentCode = null;
        $offset = 0;
        $count = 0;
        while (preg_match('/\/([^\s\/<>\[\]\(\)\{\}%]+)|(\d+)/', $differencesBody, $token, PREG_OFFSET_CAPTURE, $offset) === 1) {
            if (++$count > $this->options->maxArrayElements) {
                throw PdfParseException::resourceLimitExceeded('Encoding Differences exceeds maxArrayElements.');
            }
            if (($count & 255) === 0) { $this->guardDeadline(); }
            $offset = $token[0][1] + strlen($token[0][0]);
            if (($token[2][0] ?? '') !== '') {
                $currentCode = (int) $token[2][0];
            } elseif (($token[1][0] ?? '') !== '' && $currentCode !== null && $currentCode <= 255) {
                $differences[$currentCode++] = $this->decodePdfNameEscapes($token[1][0]);
            }
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
        $blockOffset = 0;
        while (preg_match('/begin(bfchar|bfrange)(.*?)end\1/s', $cmap, $block, PREG_OFFSET_CAPTURE, $blockOffset) === 1) {
            $blockOffset = $block[0][1] + strlen($block[0][0]);
            $range = $block[1][0] === 'bfrange';
            $body = $block[2][0];
            $offset = 0;
            $pattern = $range
                ? '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]+)>|\[([^\]]*)\])/s'
                : '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/';
            while (preg_match($pattern, $body, $entry, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $this->guardDeadline();
                $offset = $entry[0][1] + strlen($entry[0][0]);
                $source = strtoupper($entry[1][0]);
                $start = $this->parseCMapSourceCode($source);
                if ($start === null || strlen($source) % 2 !== 0) { continue; }
                if (!$range) {
                    $destination = $entry[2][0];
                    $this->accountCMapEntry(strlen($source) + strlen($destination));
                    $text = $this->hexToUtf8($destination);
                    if ($text !== '') { $map[$source] = $text; }
                } else {
                    $end = $this->parseCMapSourceCode($entry[2][0]);
                    if ($end === null || $end < $start || $end - $start > 0xFFFF) { continue; }
                    $arrayOffset = 0;
                    for ($code = $start; $code <= $end; $code++) {
                        if (($entry[3][0] ?? '') !== '') {
                            $destination = $this->incrementHexString($entry[3][0], $code - $start);
                        } elseif (preg_match('/<([0-9A-Fa-f]+)>/', $entry[4][0] ?? '', $target, PREG_OFFSET_CAPTURE, $arrayOffset) === 1) {
                            $arrayOffset = $target[0][1] + strlen($target[0][0]);
                            $destination = $target[1][0];
                        } else { break; }
                        if ($destination === null) { continue; }
                        $this->accountCMapEntry(strlen($source) + strlen($destination));
                        $text = $this->hexToUtf8($destination);
                        if ($text !== '') {
                            $key = strtoupper(str_pad(dechex($code), strlen($source), '0', STR_PAD_LEFT));
                            $map[$key] = $text;
                        }
                    }
                }
                $maxCodeBytes = max($maxCodeBytes, intdiv(strlen($source), 2));
            }
        }
        return ['map' => $map, 'max_code_bytes' => $maxCodeBytes];
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
    private function extractStreamInfoFromObjectBody(string $objectBody, array &$objects = []): ?array
    {
        $dictionary = $this->extractFirstDictionary($objectBody);
        if ($dictionary === null) { return null; }
        $dictionaryStart = strpos($objectBody, $dictionary);
        $streamPos = $dictionaryStart + strlen($dictionary);
        $this->skipContentWhitespaceAndComments($objectBody, $streamPos);
        if (substr($objectBody, $streamPos, 6) !== 'stream') { return null; }
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
    private function resolveStreamLength(string $dictionary, array &$objects): ?int
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
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $this->skipContentWhitespaceAndComments($text, $offset);
            $start = $offset;
            $item = $this->readPdfArrayItem($text, $offset);
            if (str_starts_with($item, '<<')) {
                return str_ends_with($item, '>>') ? $item : null;
            }
            if ($offset <= $start) { break; }
        }
        return null;
    }

    /**
     * @param string[] $warnings
     */
    private function decodeStream(
        string $dictionary,
        string $stream,
        array &$warnings,
        int $objectId,
        bool $cacheResult = false
    ): string
    {
        $this->guardDeadline();
        if ($cacheResult && $objectId > 0 && array_key_exists($objectId, $this->context->decodedStreamCache)) {
            return $this->context->decodedStreamCache[$objectId];
        }
        if (strlen($stream) > $this->options->maxStreamBytes) {
            throw PdfParseException::resourceLimitExceeded('Compressed PDF stream exceeds maxStreamBytes.');
        }

        $filterPipeline = $this->parseFilterPipeline($dictionary);
        if ($filterPipeline === []) {
            $this->accountIntermediateBytes(strlen($stream));
            $this->accountDecodedBytes(strlen($stream));
            if ($cacheResult && $objectId > 0) {
                $this->cacheStringResult('decodedStreamCache', $objectId, $stream);
            }
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
                $this->addWarning($warnings, 'Unsupported or failed stream filter "' . $filter . '" on object ' . $objectId . '.');
                if ($cacheResult && $objectId > 0) {
                    $this->context->decodedStreamCache[$objectId] = '';
                }
                return '';
            }

            if (strlen($result) > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('Decoded PDF stream exceeds maxStreamBytes.');
            }

            $this->accountIntermediateBytes(strlen($result));
            $decoded = $result;

            if (in_array($filter, ['FlateDecode', 'Fl', 'LZWDecode', 'LZW'], true)) {
                $postPredictor = $this->applyPredictor($decoded, $decodeParams);
                if ($postPredictor === false) {
                    $this->addWarning($warnings, 'Predictor decode failed for filter "' . $filter . '" on object ' . $objectId . '.');
                    if ($cacheResult && $objectId > 0) {
                        $this->context->decodedStreamCache[$objectId] = '';
                    }
                    return '';
                }
                $decoded = $postPredictor;
            }
        }

        $this->accountDecodedBytes(strlen($decoded));
        if ($cacheResult && $objectId > 0) {
            $this->cacheStringResult('decodedStreamCache', $objectId, $decoded);
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

            if (count($items) >= $this->options->maxArrayElements) {
                throw PdfParseException::resourceLimitExceeded('PDF array exceeds maxArrayElements.');
            }
            $this->guardDeadline();
            $items[] = $this->readPdfArrayItem($arrayBody, $offset);
        }

        return $items;
    }

    private function readPdfArrayItem(string $text, int &$offset, int $nesting = 0): string
    {
        if ($nesting > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Dictionary nesting exceeds maxRecursionDepth.');
        }
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
                if ($nesting + $depth > $this->options->maxRecursionDepth) {
                    throw PdfParseException::resourceLimitExceeded('Dictionary/array nesting exceeds maxRecursionDepth.');
                }
                if (($offset & 1023) === 0) { $this->guardDeadline(); }
                $this->skipContentWhitespaceAndComments($text, $offset);
                if ($offset >= $length) { break; }
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
                if ($nesting + $depth > $this->options->maxRecursionDepth) {
                    throw PdfParseException::resourceLimitExceeded('Dictionary/array nesting exceeds maxRecursionDepth.');
                }
                if (($offset & 1023) === 0) { $this->guardDeadline(); }
                $this->skipContentWhitespaceAndComments($text, $offset);
                if ($offset >= $length) { break; }
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
                    $this->readPdfArrayItem($text, $offset, $nesting + $depth);
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
                $this->skipContentWhitespaceAndComments($text, $offset);
                if ($offset >= $length) { break; }
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
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP, ZLIB_ENCODING_RAW] as $encoding) {
            $inflater = inflate_init($encoding);
            if ($inflater === false) { continue; }
            $out = '';
            for ($offset = 0, $length = strlen($stream); $offset < $length; $offset += 1024) {
                $this->guardDeadline();
                $part = @inflate_add($inflater, substr($stream, $offset, 1024), ZLIB_SYNC_FLUSH);
                if ($part === false) { break; }
                if (strlen($part) > $this->options->maxStreamBytes - strlen($out)) {
                    throw PdfParseException::resourceLimitExceeded('Flate stream exceeds maxStreamBytes.');
                }
                if (strlen($out) + strlen($part) > $this->options->maxDecodedBytesTotal - $this->context->intermediateDecodedBytes) {
                    throw PdfParseException::resourceLimitExceeded('Flate stream exceeds maxDecodedBytesTotal.');
                }
                $out .= $part;
                if (inflate_get_status($inflater) === ZLIB_STREAM_END) { return $out; }
            }
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
            if (($i & 1023) === 0) { $this->guardDeadline(); }
            $char = $clean[$i];

            if ($char === 'z') {
                if ($group !== '') {
                    return false;
                }
                if (strlen($result) + 4 > $this->options->maxStreamBytes) {
                    throw PdfParseException::resourceLimitExceeded('ASCII85 stream exceeds maxStreamBytes.');
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
    private function decodeLzw(string $stream, array $decodeParams, ?int &$consumedBytes = null): string|false
    {
        $consumedBytes = null;
        $dataLength = strlen($stream);
        if ($dataLength === 0) {
            return '';
        }

        $earlyChange = ($decodeParams['EarlyChange'] ?? 1) === 0 ? 0 : 1;
        $clearCode = 256;
        $eodCode = 257;
        $maxCode = 4095;
        $byteOffset = 0;
        $bitBuffer = 0;
        $bitsInBuffer = 0;

        $dictionary = [];
        for ($i = 0; $i <= 255; $i++) {
            $dictionary[$i] = chr($i);
        }
        $codeWidth = 9;
        $nextCode = 258;
        $previousCode = null;
        $output = '';
        $codesRead = 0;

        while (true) {
            while ($bitsInBuffer < $codeWidth && $byteOffset < $dataLength) {
                $bitBuffer = ($bitBuffer << 8) | ord($stream[$byteOffset++]);
                $bitsInBuffer += 8;
            }
            if ($bitsInBuffer < $codeWidth) {
                break;
            }
            $bitsInBuffer -= $codeWidth;
            $code = ($bitBuffer >> $bitsInBuffer) & ((1 << $codeWidth) - 1);
            $bitBuffer = $bitsInBuffer === 0
                ? 0
                : $bitBuffer & ((1 << $bitsInBuffer) - 1);
            $codesRead++;
            if (($codesRead & 4095) === 0) {
                $this->guardDeadline();
            }

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
                $consumedBytes = $byteOffset;
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
            if (strlen($output) > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('LZW stream exceeds maxStreamBytes.');
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
            $this->guardDeadline();
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
                if (strlen($out) > $this->options->maxStreamBytes) {
                    throw PdfParseException::resourceLimitExceeded('RunLength stream exceeds maxStreamBytes.');
                }
                $offset += $literalLength;
                continue;
            }

            if ($offset >= $length) {
                return false;
            }

            $repeatCount = 257 - $runLength;
            $out .= str_repeat($stream[$offset], $repeatCount);
            if (strlen($out) > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('RunLength stream exceeds maxStreamBytes.');
            }
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

        if ($colors > 65536 || $columns > 65536 || $bitsPerComponent > 16) { return false; }
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
            $this->guardDeadline();
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
            $this->guardDeadline();
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
        array &$objects,
        array &$warnings,
        array $formStack,
        ?array &$state = null
    ): string
    {
        $offset = 0;
        $length = strlen($contentStream);
        $state ??= ['inside' => false, 'font' => null, 'stack' => [], 'operands' => []];
        $operands = &$state['operands'];
        $parts = [];
        $state['last_char'] ??= '';
        $lastChar = &$state['last_char'];
        $insideTextObject = &$state['inside'];
        $currentFont = &$state['font'];

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
            $this->context->metrics['content_operators']++;
            if ($this->context->metrics['content_operators'] > $this->options->maxContentOperators) {
                throw PdfParseException::resourceLimitExceeded('Content operators exceed maxContentOperators.');
            }
            if ((((int) $this->context->metrics['content_operators']) & 4095) === 0) {
                $this->guardDeadline();
            }

            switch ($operator) {
                case 'BI':
                    $this->skipInlineImage($contentStream, $offset, $warnings);
                    $operands = [];
                    break;
                case 'q':
                    if (count($state['stack']) >= $this->options->maxRecursionDepth) {
                        throw PdfParseException::resourceLimitExceeded('Graphics state exceeds maxRecursionDepth.');
                    }
                    $state['stack'][] = $currentFont;
                    $operands = [];
                    break;
                case 'Q':
                    if ($state['stack'] !== []) { $currentFont = array_pop($state['stack']); }
                    $operands = [];
                    break;
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
                            $this->appendTextPart($parts, $text);
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
                                $this->appendTextPart($parts, $tjText);
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
                                $this->appendTextPart($parts, $text);
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
                                $this->appendTextPart($parts, $text);
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
                            if ($lastChar !== '') {
                                $this->appendNewlineToArray($parts, $lastChar);
                            }
                            $this->appendTextPart($parts, $nestedText);
                            $lastChar = $nestedText[strlen($nestedText) - 1];
                        }
                    }
                    $operands = [];
                    break;
                default:
                    $operands = [];
            }
        }

        return implode('', $parts);
    }

    /** Skip inline image bytes without interpreting binary data as PDF operators. */
    private function skipInlineImage(string $content, int &$offset, array &$warnings): void
    {
        $dictionary = [];
        $items = 0;
        while ($offset < strlen($content)) {
            $key = $this->readContentToken($content, $offset);
            if (($key['value'] ?? null) === 'ID' && ($key['type'] ?? null) === 'operator') { break; }
            if (++$items > 64 || ($key['type'] ?? null) !== 'name') {
                $this->addWarning($warnings, 'Malformed inline image dictionary; remaining content stream skipped.');
                $offset = strlen($content);
                return;
            }
            $dictionary[$key['value']] = $this->readContentToken($content, $offset);
        }
        if (($content[$offset] ?? '') === "\r" && ($content[$offset + 1] ?? '') === "\n") {
            $offset += 2;
        } elseif (isset($content[$offset]) && $this->isPdfWhitespace($content[$offset])) {
            $offset++;
        }
        $start = $offset;
        $filterToken = $dictionary['F'] ?? $dictionary['Filter'] ?? null;
        if (($filterToken['type'] ?? null) === 'array') { $filterToken = $filterToken['value'][0] ?? null; }
        $filter = $filterToken['value'] ?? '';
        $end = null;
        if ($filter === '') {
            $width = (int) ($dictionary['W']['value'] ?? $dictionary['Width']['value'] ?? 0);
            $height = (int) ($dictionary['H']['value'] ?? $dictionary['Height']['value'] ?? 0);
            $mask = ($dictionary['IM']['value'] ?? $dictionary['ImageMask']['value'] ?? '') === 'true';
            $bits = $mask ? 1 : (int) ($dictionary['BPC']['value'] ?? $dictionary['BitsPerComponent']['value'] ?? 0);
            $space = $dictionary['CS']['value'] ?? $dictionary['ColorSpace']['value'] ?? '';
            $colors = $mask ? 1 : match ($space) {
                'G', 'DeviceGray' => 1, 'RGB', 'DeviceRGB' => 3, 'CMYK', 'DeviceCMYK' => 4, default => 0,
            };
            if ($width > 0 && $width <= 1_000_000 && $height > 0 && $height <= 1_000_000 && in_array($bits, [1, 2, 4, 8, 16], true) && $colors > 0) {
                $bytes = intdiv($width * $bits * $colors + 7, 8) * $height;
                if ($bytes > $this->options->maxStreamBytes) {
                    throw PdfParseException::resourceLimitExceeded('Inline image exceeds maxStreamBytes.');
                }
                $end = $start + $bytes;
            }
        } elseif (in_array($filter, ['AHx', 'ASCIIHexDecode', 'A85', 'ASCII85Decode'], true)) {
            $marker = in_array($filter, ['AHx', 'ASCIIHexDecode'], true) ? '>' : '~>';
            $position = strpos($content, $marker, $start);
            if ($position !== false) { $end = $position + strlen($marker); }
        } elseif (in_array($filter, ['RL', 'RunLengthDecode'], true)) {
            $position = $start;
            while ($position < strlen($content)) {
                $this->guardDeadline();
                $run = ord($content[$position++]);
                if ($run === 128) { $end = $position; break; }
                $position += $run < 128 ? $run + 1 : 1;
            }
        } elseif (in_array($filter, ['DCT', 'DCTDecode'], true)) {
            $end = $this->inlineJpegEnd($content, $start);
        } elseif (in_array($filter, ['LZW', 'LZWDecode'], true)) {
            $consumed = null;
            $decoded = $this->decodeLzw(substr($content, $start), [], $consumed);
            if ($decoded !== false && $consumed !== null) {
                $this->accountIntermediateBytes(strlen($decoded));
                $end = $start + $consumed;
            }
        } elseif (in_array($filter, ['Fl', 'FlateDecode'], true)) {
            $inflater = inflate_init(ZLIB_ENCODING_DEFLATE);
            if ($inflater !== false) {
                $position = $start;
                $decodedBytes = 0;
                while ($position < strlen($content)) {
                    $this->guardDeadline();
                    $chunk = substr($content, $position, 1024);
                    $decoded = @inflate_add($inflater, $chunk, ZLIB_SYNC_FLUSH);
                    if ($decoded === false) { break; }
                    $decodedBytes += strlen($decoded);
                    if ($decodedBytes > $this->options->maxStreamBytes) {
                        throw PdfParseException::resourceLimitExceeded('Inline image exceeds maxStreamBytes.');
                    }
                    $this->accountIntermediateBytes(strlen($decoded));
                    if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                        $end = $start + inflate_get_read_len($inflater);
                        break;
                    }
                    $position += strlen($chunk);
                }
            }
        }
        if ($end !== null && $end - $start <= $this->options->maxStreamBytes && $end <= strlen($content)) {
            $offset = $end;
            $this->skipContentWhitespaceAndComments($content, $offset);
            if (substr($content, $offset, 2) === 'EI' && $this->tokenEndsAt($content, $offset + 2)) {
                $offset += 2;
                return;
            }
        }
        // Ambiguous binary boundaries cannot be recovered by guessing the first "EI".
        $this->addWarning($warnings, 'Unsupported or malformed inline image; remaining content stream skipped.');
        $offset = strlen($content);
    }

    private function inlineJpegEnd(string $content, int $start): ?int
    {
        if (substr($content, $start, 2) !== "\xFF\xD8") { return null; }
        $offset = $start + 2;
        $length = strlen($content);
        while ($offset < $length) {
            $this->guardDeadline();
            $marker = strpos($content, "\xFF", $offset);
            if ($marker === false) { return null; }
            $offset = $marker + 1;
            while (($content[$offset] ?? '') === "\xFF") { $offset++; }
            if ($offset >= $length) { return null; }
            $code = ord($content[$offset++]);
            if ($offset - $start > $this->options->maxStreamBytes) {
                throw PdfParseException::resourceLimitExceeded('Inline JPEG exceeds maxStreamBytes.');
            }
            if ($code === 0xD9) { return $offset; }
            if ($code === 0 || $code === 1 || ($code >= 0xD0 && $code <= 0xD7)) { continue; }
            if ($code === 0xD8 || $offset + 1 >= $length) { return null; }
            $segmentLength = (ord($content[$offset]) << 8) | ord($content[$offset + 1]);
            if ($segmentLength < 2 || $segmentLength > $length - $offset) { return null; }
            $offset += $segmentLength;
        }
        return null;
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
        array &$objects,
        array &$warnings,
        array $formStack
    ): string
    {
        if (!isset($xObjectMap[$xObjectName])) {
            return '';
        }

        $xObjectId = $xObjectMap[$xObjectName];
        if (!$this->ensureObject($xObjectId, $objects, true)) {
            return '';
        }

        if (isset($formStack[$xObjectId])) {
            $this->addWarning($warnings, 'Recursive Form XObject reference detected at object ' . $xObjectId . '.');
            return '';
        }
        if (count($formStack) >= $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Form XObject nesting exceeds maxRecursionDepth.');
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

        $hasOwnResources = preg_match('/\/Resources\b/', $streamInfo['dictionary']) === 1;
        if ($hasOwnResources && array_key_exists($xObjectId, $this->context->formTextCache)) {
            return $this->context->formTextCache[$xObjectId];
        }

        $decodedStream = $this->decodeStream(
            $streamInfo['dictionary'],
            $streamInfo['stream'],
            $warnings,
            $xObjectId,
            !$hasOwnResources
        );
        if ($decodedStream === '') {
            return '';
        }

        $resourceBodies = $this->extractResourceDictionaryBodiesFromObjectBody($xObjectBody, $objects);
        $formFontMaps = $parentFontMaps;
        $formXObjectMap = $xObjectMap;
        foreach ($this->buildFontMapsFromResourceBodies($resourceBodies, $objects, $warnings) as $name => $fontMap) {
            $formFontMaps[$name] = $fontMap;
        }
        foreach ($resourceBodies as $resourceBody) {
            foreach ($this->extractXObjectMapFromResourceDictionary($resourceBody, $objects) as $name => $objectId) {
                $formXObjectMap[$name] = $objectId;
            }
        }

        $formStack[$xObjectId] = true;

        $text = $this->extractTextFromContentStream(
            $decodedStream,
            $formFontMaps,
            $formXObjectMap,
            $objects,
            $warnings,
            $formStack
        );

        if ($hasOwnResources) {
            $this->cacheStringResult('formTextCache', $xObjectId, $text);
        }

        return $text;
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
                    $this->appendTextPart($parts, $text);
                }
                continue;
            }

            if ($token['type'] === 'number') {
                $value = (float) $token['value'];
                if ($value < -250) {
                    $this->appendTextPart($parts, ' ');
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

            $this->context->metrics['content_tokens']++;
            if ($this->context->metrics['content_tokens'] > $this->options->maxContentTokens) {
                throw PdfParseException::resourceLimitExceeded('Content tokens exceed maxContentTokens.');
            }
            if ((((int) $this->context->metrics['content_tokens']) & 255) === 0) { $this->guardDeadline(); }
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
        if ($bytes === '') { return ''; }
        if (strlen($bytes) > $this->options->maxExtractedTextBytes) {
            throw PdfParseException::resourceLimitExceeded('Text operand exceeds maxExtractedTextBytes.');
        }

        if ($fontKey !== null && isset($fontMaps[$fontKey])) {
            $fontDef = $fontMaps[$fontKey];
            if ($fontDef['map'] !== []) {
                $mapped = $this->decodeWithFontMap($bytes, $fontDef['map'], $fontDef['max_code_bytes'], $fontDef);
                if ($mapped !== '') {
                    return $mapped;
                }
            }

            $fallbackMapped = $this->decodeWithFontEncodingFallback($bytes, $fontDef);
            if ($fallbackMapped !== '') {
                return $fallbackMapped;
            }
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return $this->convertEncoding(substr($bytes, 2), 'UTF-16BE');
        }

        if (str_starts_with($bytes, "\xFF\xFE")) {
            return $this->convertEncoding(substr($bytes, 2), 'UTF-16LE');
        }

        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }

        $converted = $this->convertEncoding($bytes, 'Windows-1252');
        if ($converted !== '') {
            return $converted;
        }

        return $bytes;
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

        if ($differences === []) {
            return $this->convertSingleByteRun($bytes, $encodingName);
        }

        $out = '';
        $run = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);

            if (isset($differences[$code])) {
                $mapped = $this->glyphNameToUnicode($differences[$code]);
                if ($mapped !== '') {
                    if ($run !== '') {
                        $out .= $this->convertSingleByteRun($run, $encodingName);
                        $run = '';
                    }
                    $out .= $mapped;
                    continue;
                }
            }

            $run .= $bytes[$i];
        }

        if ($run !== '') {
            $out .= $this->convertSingleByteRun($run, $encodingName);
        }

        return $out;
    }

    private function convertSingleByteRun(string $bytes, string $encodingName): string
    {
        if ($bytes === '') {
            return '';
        }

        if ($encodingName === 'MacRomanEncoding') {
            return $this->convertEncoding($bytes, 'Macintosh');
        }

        if ($encodingName === 'PDFDocEncoding') {
            $out = '';
            $length = strlen($bytes);
            for ($i = 0; $i < $length; $i++) {
                $out .= $this->decodePdfDocByte(ord($bytes[$i]));
            }
            return $out;
        }

        if (in_array($encodingName, ['StandardEncoding', 'SymbolEncoding', 'ZapfDingbatsEncoding'], true)) {
            $out = '';
            $length = strlen($bytes);
            for ($i = 0; $i < $length; $i++) {
                $out .= $this->decodeStandardEncodingByte(ord($bytes[$i]));
            }
            return $out;
        }

        $converted = $this->convertEncoding($bytes, 'Windows-1252');
        return $converted !== '' ? $converted : $bytes;
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
                } catch (\Throwable) {
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
                } catch (\Throwable) {
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
                    $this->appendBoundedText($out, $map[$code]);
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
        if ($hex === '' || strlen($hex) % 4 !== 0) { return ''; }
        $bytes = @hex2bin($hex);
        if ($bytes === false) { return ''; }
        $out = '';
        for ($i = 0, $length = strlen($bytes); $i < $length; $i += 2) {
            if (($i & 4095) === 0) { $this->guardDeadline(); }
            $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
            if ($code >= 0xD800 && $code <= 0xDBFF) {
                if ($i + 3 >= $length) { return ''; }
                $low = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
                if ($low < 0xDC00 || $low > 0xDFFF) { return ''; }
                $code = 0x10000 + (($code - 0xD800) << 10) + $low - 0xDC00;
                $i += 2;
            } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                return '';
            }
            $out .= $this->unicodeCodePointToUtf8($code);
        }
        return $out;
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
        if ($this->context->arrayDepth === 0) { $this->context->arrayElements = 0; }
        if (++$this->context->arrayDepth > $this->options->maxRecursionDepth) {
            $this->context->arrayDepth--;
            throw PdfParseException::resourceLimitExceeded('Content array nesting exceeds maxRecursionDepth.');
        }
        try {
            $items = [];
            $length = strlen($content);
            $offset++;
            while ($offset < $length) {
                $this->skipContentWhitespaceAndComments($content, $offset);
                if ($offset >= $length) { break; }
                if ($content[$offset] === ']') { $offset++; break; }
                if (++$this->context->arrayElements > $this->options->maxArrayElements) {
                    throw PdfParseException::resourceLimitExceeded('Content array exceeds maxArrayElements.');
                }
                $token = $this->readContentToken($content, $offset);
                if ($token === null) { break; }
                $items[] = $token;
            }
            return $items;
        } finally {
            $this->context->arrayDepth--;
        }
    }

    private function readLiteralString(string $content, int &$offset): string
    {
        $length = strlen($content);
        $offset++; // skip '('
        $depth = 1;
        $out = '';

        while ($offset < $length) {
            if (($offset & 1023) === 0) { $this->guardDeadline(); }
            $runLength = strcspn($content, "\\()", $offset, min(16384, $length - $offset));
            if ($runLength > 0) {
                $this->guardDeadline();
                if ($runLength > $this->options->maxStreamBytes - strlen($out)) {
                    throw PdfParseException::resourceLimitExceeded('String token exceeds maxStreamBytes.');
                }
                $out .= substr($content, $offset, $runLength);
                $offset += $runLength;
                continue;
            }
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
            if (($offset & 1023) === 0) { $this->guardDeadline(); }
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
            if (($offset & 1023) === 0) { $this->guardDeadline(); }
            $char = $content[$offset];
            if ($this->isPdfWhitespace($char) || $this->isPdfDelimiter($char)) {
                break;
            }
            $offset++;
        }

        return $this->decodePdfNameEscapes(substr($content, $start, $offset - $start));
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
            if (($offset & 1023) === 0) { $this->guardDeadline(); }
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
            if (($offset & 1023) === 0) { $this->guardDeadline(); }
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

    private function appendNewlineToArray(array &$parts, string &$lastChar): void
    {
        if ($lastChar === '' || $lastChar === "\n") {
            return;
        }

        $this->appendTextPart($parts, "\n");
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
