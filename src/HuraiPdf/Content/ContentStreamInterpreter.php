<?php

declare(strict_types=1);

namespace HuraiPdf\Content;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\PdfObject;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class ContentStreamInterpreter extends Subsystem
{
    /**
     * @param array<int, PdfObject> $objects
     * @param string[] $warnings
     */
    public function extractPageText(int $pageObjectId, array &$objects, array &$warnings): string
    {
        $base = $this->context->operandBytes;
        try { return $this->extractPageTextInternal($pageObjectId, $objects, $warnings); }
        finally { $this->context->operandBytes = $base; }
    }

    private function extractPageTextInternal(int $pageObjectId, array &$objects, array &$warnings): string
    {
        if (!$this->session->reader->ensureObject($pageObjectId, $objects)) {
            return '';
        }

        $pageBody = $objects[$pageObjectId]->body;
        $fontMaps = $this->session->resources->buildPageFontMaps($pageObjectId, $pageBody, $objects, $warnings);
        $xObjectMap = $this->session->resources->buildPageXObjectMap($pageObjectId, $pageBody, $objects);
        $contentObjectIds = $this->session->resources->resolvePageContentObjectIds($pageBody, $objects);

        if ($contentObjectIds === []) {
            return '';
        }

        $streamTexts = [];
        $state = ['inside' => false, 'font' => null, 'stack' => [], 'operands' => []];
        foreach ($contentObjectIds as $contentObjectId) {
            if (!isset($objects[$contentObjectId])) {
                continue;
            }

            $streamInfo = $this->session->reader->extractStreamInfoFromObjectBody($objects[$contentObjectId]->body, $objects);
            if ($streamInfo === null) {
                continue;
            }

            $decodedStream = $this->session->decoder->decodeStream(
                $streamInfo['dictionary'],
                $streamInfo['stream'],
                $warnings,
                $contentObjectId,
                objects: $objects
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
        $ownsState = $state === null;
        $baseOperandBytes = $this->context->operandBytes - ($state['operand_bytes'] ?? 0);
        $state ??= ['inside' => false, 'font' => null, 'stack' => [], 'operands' => []];
        $operands = &$state['operands'];
        $parts = [];
        $state['last_char'] ??= '';
        $lastChar = &$state['last_char'];
        $insideTextObject = &$state['inside'];
        $currentFont = &$state['font'];

        try {
            while ($offset < $length) {
                $token = $this->session->syntax->readContentToken($contentStream, $offset);
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
                    $this->session->budget->guardDeadline();
                }

                switch ($operator) {
                    case 'BI':
                        $this->skipInlineImage($contentStream, $offset, $warnings);
                        break;
                    case 'q':
                        if (count($state['stack']) >= $this->options->maxRecursionDepth) {
                            throw PdfParseException::resourceLimitExceeded('Graphics state exceeds maxRecursionDepth.');
                        }
                        $state['stack'][] = $currentFont;
                        break;
                    case 'Q':
                        if ($state['stack'] !== []) { $currentFont = array_pop($state['stack']); }
                        break;
                    case 'BT':
                        $insideTextObject = true;
                        break;
                    case 'ET':
                        $insideTextObject = false;
                        $this->appendNewlineToArray($parts, $lastChar);
                        break;
                    case 'Tj':
                        if ($insideTextObject && $operands !== []) {
                            $text = $this->tokenToText($operands[count($operands) - 1], $currentFont, $fontMaps);
                            if ($text !== '') {
                                $this->session->budget->appendTextPart($parts, $text);
                                $lastChar = $text[strlen($text) - 1];
                            }
                        }
                        break;
                    case 'TJ':
                        if ($insideTextObject && $operands !== []) {
                            $candidate = $operands[count($operands) - 1];
                            if ($candidate['type'] === 'array') {
                                $tjText = $this->extractTextFromTJArray($candidate['value'], $currentFont, $fontMaps);
                                if ($tjText !== '') {
                                    $this->session->budget->appendTextPart($parts, $tjText);
                                    $lastChar = $tjText[strlen($tjText) - 1];
                                }
                            }
                        }
                        break;
                    case '\'':
                        if ($insideTextObject) {
                            $this->appendNewlineToArray($parts, $lastChar);
                            if ($operands !== []) {
                                $text = $this->tokenToText($operands[count($operands) - 1], $currentFont, $fontMaps);
                                if ($text !== '') {
                                    $this->session->budget->appendTextPart($parts, $text);
                                    $lastChar = $text[strlen($text) - 1];
                                }
                            }
                        }
                        break;
                    case '"':
                        if ($insideTextObject) {
                            $this->appendNewlineToArray($parts, $lastChar);
                            if ($operands !== []) {
                                $text = $this->tokenToText($operands[count($operands) - 1], $currentFont, $fontMaps);
                                if ($text !== '') {
                                    $this->session->budget->appendTextPart($parts, $text);
                                    $lastChar = $text[strlen($text) - 1];
                                }
                            }
                        }
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
                        break;
                    case 'T*':
                    case 'Tm':
                        if ($insideTextObject) {
                            $this->appendNewlineToArray($parts, $lastChar);
                        }
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
                                if ($lastChar !== '' && $lastChar !== "\n") {
                                    $this->appendNewlineToArray($parts, $lastChar);
                                }
                                $this->session->budget->appendTextPart($parts, $nestedText);
                                $lastChar = $nestedText[strlen($nestedText) - 1];
                            }
                        }
                        break;
                }
                $operands = [];
                unset($token, $candidate, $last, $xObjectName);
                // Font names survive Tf and q/Q, so retain their allocation charge.
                $retained = 2 * strlen($currentFont ?? '');
                foreach ($state['stack'] as $font) { $retained += 512 + 2 * strlen($font ?? ''); }
                unset($font);
                if ($retained > $this->options->maxOperandBytes - $baseOperandBytes) {
                    throw PdfParseException::resourceLimitExceeded('Content operands exceed maxOperandBytes.');
                }
                $this->context->operandBytes = $baseOperandBytes + $retained;
            }

            return implode('', $parts);
        } finally {
            $state['operand_bytes'] = $this->context->operandBytes - $baseOperandBytes;
            if ($ownsState) { $this->context->operandBytes = $baseOperandBytes; }
        }
    }

    /** Skip inline image bytes without interpreting binary data as PDF operators. */
    private function skipInlineImage(string $content, int &$offset, array &$warnings): void
    {
        $dictionary = [];
        $items = 0;
        while ($offset < strlen($content)) {
            $key = $this->session->syntax->readContentToken($content, $offset);
            if (($key['value'] ?? null) === 'ID' && ($key['type'] ?? null) === 'operator') { break; }
            if (++$items > 64 || ($key['type'] ?? null) !== 'name') {
                $this->session->budget->addWarning($warnings, 'Malformed inline image dictionary; remaining content stream skipped.');
                $offset = strlen($content);
                return;
            }
            $dictionary[$key['value']] = $this->session->syntax->readContentToken($content, $offset);
        }
        if (($content[$offset] ?? '') === "\r" && ($content[$offset + 1] ?? '') === "\n") {
            $offset += 2;
        } elseif (isset($content[$offset]) && $this->session->syntax->isPdfWhitespace($content[$offset])) {
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
                $this->session->budget->guardDeadline();
                $run = ord($content[$position++]);
                if ($run === 128) { $end = $position; break; }
                $position += $run < 128 ? $run + 1 : 1;
            }
        } elseif (in_array($filter, ['DCT', 'DCTDecode'], true)) {
            $end = $this->inlineJpegEnd($content, $start);
        } elseif (in_array($filter, ['LZW', 'LZWDecode'], true)) {
            $consumed = null;
            $decoded = $this->session->decoder->decodeLzw($content, [], $consumed, true, $start);
            if ($decoded !== false && $consumed !== null) {
                $end = $start + $consumed;
            }
        } elseif (in_array($filter, ['Fl', 'FlateDecode'], true)) {
            $inflater = inflate_init(ZLIB_ENCODING_DEFLATE);
            if ($inflater !== false) {
                $position = $start;
                $decodedBytes = 0;
                while ($position < strlen($content)) {
                    $this->session->budget->guardDeadline();
                    $chunk = substr($content, $position, 1024);
                    $decoded = @inflate_add($inflater, $chunk, ZLIB_SYNC_FLUSH);
                    if ($decoded === false) { break; }
                    $decodedBytes += strlen($decoded);
                    if ($decodedBytes > $this->options->maxStreamBytes) {
                        throw PdfParseException::resourceLimitExceeded('Inline image exceeds maxStreamBytes.');
                    }
                    $this->session->budget->accountIntermediateBytes(strlen($decoded));
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
            $this->session->syntax->skipContentWhitespaceAndComments($content, $offset);
            if (substr($content, $offset, 2) === 'EI' && $this->session->reader->tokenEndsAt($content, $offset + 2)) {
                $offset += 2;
                return;
            }
        }
        // Ambiguous binary boundaries cannot be recovered by guessing the first "EI".
        $this->session->budget->addWarning($warnings, 'Unsupported or malformed inline image; remaining content stream skipped.');
        $offset = strlen($content);
    }

    private function inlineJpegEnd(string $content, int $start): ?int
    {
        if (substr($content, $start, 2) !== "\xFF\xD8") { return null; }
        $offset = $start + 2;
        $length = strlen($content);
        while ($offset < $length) {
            $this->session->budget->guardDeadline();
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
        if (!$this->session->reader->ensureObject($xObjectId, $objects, true)) {
            return '';
        }

        if (isset($formStack[$xObjectId])) {
            $this->session->budget->addWarning($warnings, 'Recursive Form XObject reference detected at object ' . $xObjectId . '.');
            return '';
        }
        if (count($formStack) >= $this->options->maxRecursionDepth) {
            throw PdfParseException::resourceLimitExceeded('Form XObject nesting exceeds maxRecursionDepth.');
        }

        $xObjectBody = $objects[$xObjectId]->body;
        $entries = $this->session->syntax->dictionaryEntries($xObjectBody);
        if ($this->session->syntax->nameValue($entries['Subtype'] ?? '') !== 'Form') {
            return '';
        }

        $streamInfo = $this->session->reader->extractStreamInfoFromObjectBody($xObjectBody, $objects);
        if ($streamInfo === null) {
            return '';
        }

        $hasOwnResources = array_key_exists('Resources', $entries);
        if ($hasOwnResources && array_key_exists($xObjectId, $this->context->formTextCache)) {
            return $this->context->formTextCache[$xObjectId];
        }

        $decodedStream = $this->session->decoder->decodeStream(
            $streamInfo['dictionary'],
            $streamInfo['stream'],
            $warnings,
            $xObjectId,
            !$hasOwnResources,
            $objects
        );
        if ($decodedStream === '') {
            return '';
        }

        $resourceBodies = $this->session->resources->extractResourceDictionaryBodiesFromObjectBody($xObjectBody, $objects);
        $formFontMaps = $parentFontMaps;
        $formXObjectMap = $xObjectMap;
        foreach ($this->session->resources->buildFontMapsFromResourceBodies($resourceBodies, $objects, $warnings) as $name => $fontMap) {
            $formFontMaps[$name] = $fontMap;
        }
        foreach ($resourceBodies as $resourceBody) {
            foreach ($this->session->resources->extractXObjectMapFromResourceDictionary($resourceBody, $objects) as $name => $objectId) {
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
            $this->session->budget->cacheStringResult('formTextCache', $xObjectId, $text);
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
                $text = $this->session->encoding->decodeTextBytes($token['value'], $fontKey, $fontMaps);
                if ($text !== '') {
                    $this->session->budget->appendTextPart($parts, $text);
                }
                continue;
            }

            if ($token['type'] === 'number') {
                $value = (float) $token['value'];
                if ($value < -250) {
                    $this->session->budget->appendTextPart($parts, ' ');
                }
            }
        }

        return implode('', $parts);
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
            'string' => $this->session->encoding->decodeTextBytes((string) $token['value'], $fontKey, $fontMaps),
            'array' => $this->extractTextFromTJArray((array) $token['value'], $fontKey, $fontMaps),
            default => '',
        };
    }

    private function sanitizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

        return $text;
    }

    private function appendNewlineToArray(array &$parts, string &$lastChar): void
    {
        if ($lastChar === '') {
            return;
        }

        $this->session->budget->appendTextPart($parts, "\n");
        $lastChar = "\n";
    }

    public function normalizeText(string $text): string
    {
        $text = $this->sanitizeText($text);
        $text = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ +$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text, " \t\n");
    }
}
