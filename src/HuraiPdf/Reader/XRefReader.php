<?php

declare(strict_types=1);

namespace HuraiPdf\Reader;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\PdfObject;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class XRefReader extends Subsystem
{
    /**
     * @param InputSource $handle
     * @param string[] $warnings
     * @return array{
     *   offsets:array<int,array{offset:int,generation:int,next:int}>,
     *   compressed:array<int,array{stream_id:int,index:int}>,
     *   root_id:?int,
     *   encrypted:bool
     * }|null
     */
    public function readFileXRefIndex(InputSource $handle, int $fileSize, array &$warnings): ?array
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
        $trailer = [];
        $encrypted = false;
        $sectionBoundaries = [];
        $allObjectBoundaries = [];

        while ($queue !== []) {
            $this->session->budget->guardDeadline();
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
                $section['trailer'] += $hybrid['trailer'];
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
            $this->session->budget->assertObjectBudget(count($seenIds) - (isset($seenIds[0]) ? 1 : 0));
            if (count($visited) > $this->options->maxObjects || count($allObjectBoundaries) > $this->options->maxObjects) {
                throw PdfParseException::resourceLimitExceeded('Cross-reference history exceeds maxObjects.');
            }

            $rootId ??= $section['root_id'];
            $trailer += $section['trailer'];
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
        $this->session->budget->assertObjectBudget($indexedCount);
        $this->context->metrics['objects_indexed'] = $indexedCount;

        return [
            'offsets' => $offsetEntries,
            'compressed' => $compressedEntries,
            'root_id' => $rootId,
            'encrypted' => $encrypted,
            'trailer' => $trailer,
        ];
    }

    /**
     * @param InputSource $handle
     * @return array{offsets:array<int,array{offset:int,generation:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool,prev:?int,xref_stream_offset:?int}|null
     */
    private function readPdfLine(InputSource $handle, string &$buffer): string|false
    {
        while (true) {
            $end = strcspn($buffer, "\r\n");
            if ($end < strlen($buffer)) {
                if ($buffer[$end] === "\r" && $end + 1 === strlen($buffer) && !$handle->eof()) {
                    $part = $handle->read(4096);
                    if ($part !== false) { $buffer .= $part; }
                }
                $length = $end + (($buffer[$end] === "\r" && ($buffer[$end + 1] ?? '') === "\n") ? 2 : 1);
                $line = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);
                return $line;
            }
            if (strlen($buffer) >= 65536) { return false; }
            $this->session->budget->guardDeadline();
            $part = $handle->read(4096);
            if ($part === false || $part === '') {
                $line = $buffer;
                $buffer = '';
                return $line === '' ? false : $line;
            }
            $buffer .= $part;
        }
    }

    private function readTraditionalXRefSection(InputSource $handle, int $offset, int $fileSize): ?array
    {
        $handle->seek($offset);
        $lineBuffer = "";
        $firstLine = $this->readPdfLine($handle, $lineBuffer);
        if ($firstLine === false || trim($firstLine) !== 'xref') {
            return null;
        }

        $entries = [];
        $free = [];
        $trailerText = '';
        while (($line = $this->readPdfLine($handle, $lineBuffer)) !== false) {
            $this->session->budget->guardDeadline();
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, 'trailer')) {
                $trailerText = substr($trimmed, 7);
                while (
                    $this->session->syntax->extractFirstDictionary($trailerText) === null &&
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
            $this->session->budget->assertObjectBudget(count($entries) + count($free) + max(0, $count - ($startId === 0 ? 1 : 0)));
            for ($i = 0; $i < $count; $i++) {
                if (($i & 255) === 0) { $this->session->budget->guardDeadline(); }
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
                    $this->session->budget->assertObjectBudget(count($entries));
                }
            }
        }

        $dictionary = $this->session->syntax->extractFirstDictionary($trailerText) ?? '';
        return [
            'trailer' => $this->session->syntax->dictionaryEntries($dictionary),
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
     * @param InputSource $handle
     * @param string[] $warnings
     * @return array{offsets:array<int,array{offset:int,generation:int}>,compressed:array<int,array{stream_id:int,index:int}>,root_id:?int,encrypted:bool,prev:?int,xref_stream_offset:?int}|null
     */
    private function readXRefStreamSection(InputSource $handle, int $offset, int $fileSize, array &$warnings): ?array
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
        $decoded = $this->session->decoder->decodeStream($streamInfo['dictionary'], $streamInfo['stream'], $warnings, 0);
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
            $this->session->budget->assertObjectBudget(count($offsetEntries) + count($compressedEntries) + count($free) + max(0, $rangeCount - ($startId === 0 ? 1 : 0)));
            for ($i = 0; $i < $rangeCount; $i++) {
                if (($i & 255) === 0) { $this->session->budget->guardDeadline(); }
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
                $this->session->budget->assertObjectBudget(count($offsetEntries) + count($compressedEntries));
            }
        }

        return [
            'free' => $free,
            'offsets' => $offsetEntries,
            'trailer' => $this->session->syntax->dictionaryEntries($dictionary),
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

    /** @param InputSource $handle */
    public function readFileRange(InputSource $handle, int $offset, int $length): string
    {
        if ($length <= 0 || $handle->seek($offset) !== 0) {
            return '';
        }
        $result = '';
        while (strlen($result) < $length) {
            $this->session->budget->guardDeadline();
            $part = $handle->read(min($this->options->objectChunkSize, $length - strlen($result)));
            if ($part === false || $part === '') {
                break;
            }
            $result .= $part;
        }
        return $result;
    }

    /** @param InputSource $handle */
    private function readObjectGrowing(InputSource $handle, int $offset, int $fileSize): ?string
    {
        if ($handle->seek($offset) !== 0) {
            return null;
        }
        $chunk = '';
        $chunkSize = max(4096, $this->options->objectChunkSize);
        $maximum = min($this->options->maxObjectBytes, $fileSize - $offset);
        while (strlen($chunk) < $maximum) {
            $this->session->budget->guardDeadline();
            $part = $handle->read(min($chunkSize, $maximum - strlen($chunk)));
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
     * @param InputSource $handle
     * @param string[] $warnings
     */
    public function loadObjectFromIndex(
        int $objectId,
        array &$objects,
        array $index,
        InputSource $handle,
        array &$warnings,
        int $depth = 0
    ): bool
    {
        if ($depth > $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Object reference nesting exceeds maxRecursionDepth.');
        }
        $this->session->budget->guardDeadline();
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
        // Inspect the dictionary before allocating an object's stream payload,
        // even if a malformed page references an image as its Contents or font.
        $prefix = $this->readFileRange($handle, $entry['offset'], min(4096, $length));
        $this->context->metrics['object_bytes_read'] += strlen($prefix);
        $dictionary = $this->session->syntax->extractFirstDictionary($prefix) ?? '';
        if (($this->session->syntax->dictionaryEntries($dictionary)['Subtype'] ?? '') === '/Image') { return false; }
        if ($length > $this->options->maxObjectBytes) {
            throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
        }
        $remaining = $this->readFileRange($handle, $entry['offset'] + strlen($prefix), $length - strlen($prefix));
        $this->context->metrics['object_bytes_read'] += strlen($remaining);
        $raw = $prefix . $remaining;
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+obj\b/', $raw, $header) !== 1) {
            return false;
        }
        if ((int) $header[1] !== $objectId || (int) $header[2] !== $entry['generation']) {
            $this->session->budget->addWarning($warnings, 'Cross-reference object identity mismatch for object ' . $objectId . '.');
            return false;
        }
        $bodyStart = strlen($header[0]);
        $dictionary = $this->session->syntax->extractFirstDictionary(substr($raw, $bodyStart)) ?? '';
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
        $objects[$objectId] = $this->prepareObject($objects[$objectId], $warnings);
        $this->context->metrics['objects_loaded']++;
        return true;
    }

    public function ensureObject(int $id, array &$objects, bool $formOnly = false): bool
    {
        return isset($objects[$id]) || ($this->context->objectLoader !== null && ($this->context->objectLoader)($id, $formOnly));
    }

    /**
     * @return array<int, PdfObject>
     */
    public function collectIndirectObjects(string $content): array
    {
        $objects = [];
        $cursor = 0;
        $this->context->trailerEntries = [];
        while (preg_match('/(?:^|[\r\n])(\d+)\s+(\d+)\s+obj\b/', $content, $match, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $this->session->budget->guardDeadline();
            $this->collectTrailerEntries(substr($content, $cursor, $match[0][1] - $cursor));
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
            $this->session->budget->assertObjectBudget(count($objects) + (isset($objects[$id]) ? 0 : 1));
            if ($end - $bodyStart > $this->options->maxObjectBytes) {
                throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
            }
            $dictionary = $this->session->syntax->extractFirstDictionary(substr($content, $bodyStart, min($end - $bodyStart, 65536))) ?? '';
            $entries = $this->session->syntax->dictionaryEntries($dictionary);
            if (($entries['Subtype'] ?? '') === '/Image') { continue; }
            if (($entries['Type'] ?? '') === '/XRef') {
                $this->context->trailerEntries = $entries + $this->context->trailerEntries;
            }
            $objects[$id] = new PdfObject($id, (int) $match[2][0], substr($content, $bodyStart, $end - $bodyStart), $match[1][1], false);
        }
        $this->collectTrailerEntries(substr($content, $cursor));
        return $objects;
    }

    private function collectTrailerEntries(string $text): void
    {
        $offset = 0;
        while (preg_match('/\btrailer\s*<</', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $match[0][1] + strlen($match[0][0]) - 2;
            $dictionary = $this->session->syntax->readPdfArrayItem($text, $offset);
            $this->context->trailerEntries = $this->session->syntax->dictionaryEntries($dictionary) + $this->context->trailerEntries;
        }
    }

    public function prepareObject(PdfObject $object, array &$warnings): PdfObject
    {
        $this->context->objectGenerations[$object->id] = $object->generation;
        if ($this->context->security === null || $object->fromObjectStream || $object->id === $this->context->encryptionObjectId) { return $object; }
        $dictionary = $this->session->syntax->extractFirstDictionary($object->body) ?? '';
        if (($this->session->syntax->dictionaryEntries($dictionary)['Type'] ?? '') === '/XRef') { return $object; }
        $body = $this->session->syntax->mapObjectStrings($object->body, function (string $bytes) use ($object, &$warnings): string {
            $plain = $this->context->security->decryptString($bytes, $object->id, $object->generation);
            if ($plain === false) {
                $this->session->budget->addWarning($warnings, 'String decryption failed on object ' . $object->id . '.');
                return '';
            }
            return $plain;
        });
        return new PdfObject($object->id, $object->generation, $body, $object->offset, false);
    }

    private function locateEndObjOffset(string $content, int $searchOffset, array &$objects = []): ?int
    {
        $cursor = $searchOffset;
        $length = strlen($content);
        $dictionary = '';
        while ($cursor < $length) {
            $this->session->budget->guardDeadline();
            $this->session->syntax->skipContentWhitespaceAndComments($content, $cursor);
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
                    $this->session->syntax->skipContentWhitespaceAndComments($content, $cursor);
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
            $item = $this->session->syntax->readPdfArrayItem($content, $cursor);
            if (str_starts_with($item, '<<')) { $dictionary = $item; }
            if ($cursor <= $start) { return null; }
            if ($cursor - $searchOffset > $this->options->maxObjectBytes) {
                throw PdfParseException::resourceLimitExceeded('PDF object exceeds maxObjectBytes.');
            }
        }
        return null;
    }

    public function tokenEndsAt(string $text, int $offset): bool
    {
        return !isset($text[$offset]) || $this->session->syntax->isPdfWhitespace($text[$offset]) || $this->session->syntax->isPdfDelimiter($text[$offset]);
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
    public function expandObjectStreams(array &$objects, array &$warnings, ?array $index = null): void
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
            $this->session->budget->guardDeadline();
            if (!preg_match('/\/Type\s*\/ObjStm\b/', $containerObject->body)) {
                continue;
            }
            if (isset($this->context->expandedObjectStreams[$containerObject->id])) {
                continue;
            }
            $this->context->expandedObjectStreams[$containerObject->id] = true;

            $streamInfo = $this->extractStreamInfoFromObjectBody($containerObject->body, $objects);
            if ($streamInfo === null) {
                $this->session->budget->addWarning($warnings, 'Object stream ' . $containerObject->id . ' could not be read.');
                continue;
            }

            $decoded = $this->session->decoder->decodeStream(
                $streamInfo['dictionary'],
                $streamInfo['stream'],
                $warnings,
                $containerObject->id
            );

            if ($decoded === '') {
                $this->session->budget->addWarning($warnings, 'Object stream ' . $containerObject->id . ' produced empty decoded data.');
                continue;
            }

            if (
                preg_match('/\/N\s+(\d+)/', $streamInfo['dictionary'], $nMatch) !== 1 ||
                preg_match('/\/First\s+(\d+)/', $streamInfo['dictionary'], $fMatch) !== 1
            ) {
                $this->session->budget->addWarning($warnings, 'Object stream ' . $containerObject->id . ' missing /N or /First.');
                continue;
            }

            $count = (int) $nMatch[1];
            $this->session->budget->assertObjectBudget($count);
            $first = (int) $fMatch[1];

            if ($first < 0 || $first > strlen($decoded)) {
                $this->session->budget->addWarning($warnings, 'Invalid object stream header length.');
                continue;
            }
            $header = substr($decoded, 0, $first);
            $objectData = substr($decoded, $first);
            $tokens = [];
            $headerOffset = 0;
            for ($tokenIndex = 0; $tokenIndex < $count * 2; $tokenIndex++) {
                if (($tokenIndex & 255) === 0) { $this->session->budget->guardDeadline(); }
                if (preg_match('/\G\s*(\d+)/', $header, $token, 0, $headerOffset) !== 1) { break; }
                $tokens[] = $token[1];
                $headerOffset += strlen($token[0]);
            }

            if (count($tokens) < $count * 2) {
                $this->session->budget->addWarning($warnings, 'Object stream ' . $containerObject->id . ' index is shorter than expected.');
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                if (($i & 255) === 0) {
                    $this->session->budget->guardDeadline();
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
                $this->session->budget->assertObjectBudget(count($objects));
                $this->context->metrics['objects_loaded']++;
            }
        }
    }

    /**
     * @param array<int, PdfObject> $objects
     * @return array{dictionary:string,stream:string}|null
     */
    public function extractStreamInfoFromObjectBody(string $objectBody, array &$objects = []): ?array
    {
        $dictionary = $this->session->syntax->extractFirstDictionary($objectBody);
        if ($dictionary === null) { return null; }
        $dictionaryStart = strpos($objectBody, $dictionary);
        $streamPos = $dictionaryStart + strlen($dictionary);
        $this->session->syntax->skipContentWhitespaceAndComments($objectBody, $streamPos);
        if (substr($objectBody, $streamPos, 6) !== 'stream') { return null; }
        if ($streamPos === false) {
            return null;
        }

        $dictionaryPart = substr($objectBody, 0, $streamPos);
        $dictionary = $this->session->syntax->extractFirstDictionary($dictionaryPart);
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
}
