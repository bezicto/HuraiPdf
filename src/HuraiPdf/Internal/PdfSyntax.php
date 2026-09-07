<?php

declare(strict_types=1);

namespace HuraiPdf\Internal;

use HuraiPdf\Exception\PdfParseException;

/** @internal */
final class PdfSyntax extends Subsystem
{
    /** Read only top-level entries, preserving raw PDF values and references.
     * @return array<string, string>
     */
    public function dictionaryEntries(string $dictionary): array
    {
        $offset = 0;
        $this->skipContentWhitespaceAndComments($dictionary, $offset);
        if (substr($dictionary, $offset, 2) !== '<<') { return []; }
        $offset += 2;
        $entries = [];
        $count = 0;
        while ($offset < strlen($dictionary)) {
            $this->session->budget->guardDeadline();
            $this->skipContentWhitespaceAndComments($dictionary, $offset);
            if (substr($dictionary, $offset, 2) === '>>') { break; }
            if (($dictionary[$offset] ?? '') !== '/') { break; }
            if (++$count > $this->options->maxArrayElements) {
                throw PdfParseException::resourceLimitExceeded('Dictionary exceeds maxArrayElements.');
            }
            $key = $this->readName($dictionary, $offset);
            $this->skipContentWhitespaceAndComments($dictionary, $offset);
            if (preg_match('/\G\d+\s+\d+\s+R\b/', $dictionary, $reference, 0, $offset) === 1) {
                $value = $reference[0];
                $offset += strlen($value);
            } else {
                $value = $this->readPdfArrayItem($dictionary, $offset);
            }
            $entries[$key] = $value;
        }
        return $entries;
    }

    /** Read references in an array without matching strings, comments or nested values.
     * @return \Generator<int>
     */
    public function arrayReferenceIds(string $array): \Generator
    {
        $offset = 0;
        $this->skipContentWhitespaceAndComments($array, $offset);
        if (($array[$offset] ?? '') !== '[') { return; }
        $offset++;
        $count = 0;
        while ($offset < strlen($array)) {
            $this->skipContentWhitespaceAndComments($array, $offset);
            if (($array[$offset] ?? '') === ']' || $offset >= strlen($array)) { break; }
            $this->session->budget->guardDeadline();
            if (++$count > $this->options->maxArrayElements) {
                throw PdfParseException::resourceLimitExceeded('Reference array exceeds maxArrayElements.');
            }
            if (preg_match('/\G(\d+)\s+\d+\s+R\b/', $array, $ref, 0, $offset) === 1) {
                $offset += strlen($ref[0]);
                yield (int) $ref[1];
            } else {
                $start = $offset;
                $this->readPdfArrayItem($array, $offset);
                if ($offset <= $start) { break; }
            }
        }
    }

    public function stringBytes(string $value): ?string
    {
        $offset = 0;
        $this->skipContentWhitespaceAndComments($value, $offset);
        return match ($value[$offset] ?? '') {
            '(' => $this->readLiteralString($value, $offset),
            '<' => ($value[$offset + 1] ?? '') === '<' ? null : $this->readHexString($value, $offset),
            default => null,
        };
    }

    /** Rewrite string tokens outside stream payloads. Object-stream members must not pass here. */
    public function mapObjectStrings(string $body, \Closure $decode): string
    {
        $offset = 0;
        $copied = 0;
        $out = '';
        $depth = 0;
        while ($offset < strlen($body)) {
            $this->session->budget->guardDeadline();
            $this->skipContentWhitespaceAndComments($body, $offset);
            $char = $body[$offset] ?? '';
            $pair = substr($body, $offset, 2);
            if ($pair === '<<') { $depth++; $offset += 2; continue; }
            if ($pair === '>>') { $depth--; $offset += 2; continue; }
            if ($depth === 0 && substr($body, $offset, 6) === 'stream') { break; }
            if ($char === '(' || $char === '<') {
                $start = $offset;
                $bytes = $char === '(' ? $this->readLiteralString($body, $offset) : $this->readHexString($body, $offset);
                $out .= substr($body, $copied, $start - $copied) . '<' . bin2hex($decode($bytes)) . '>';
                $copied = $offset;
                if (strlen($out) > $this->options->maxObjectBytes) {
                    throw PdfParseException::resourceLimitExceeded('Decrypted object exceeds maxObjectBytes.');
                }
            } elseif ($char === '/') { $this->readName($body, $offset); }
            else { $offset++; }
        }
        if ($copied === 0) { return $body; }
        if (strlen($out) + strlen($body) - $copied > $this->options->maxObjectBytes) {
            throw PdfParseException::resourceLimitExceeded('Decrypted object exceeds maxObjectBytes.');
        }
        return $out . substr($body, $copied);
    }
    public function extractFirstDictionary(string $text): ?string
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
     * @return string[]
     */
    public function parsePdfArrayItems(string $arrayBody): array
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
            $this->session->budget->guardDeadline();
            $items[] = $this->readPdfArrayItem($arrayBody, $offset);
        }

        return $items;
    }

    public function readPdfArrayItem(string $text, int &$offset, int $nesting = 0): string
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
                if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
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
                if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
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

    /**
     * @return array{type:string,value:mixed}|null
     */
    public function readContentToken(string $content, int &$offset): ?array
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
            if ((((int) $this->context->metrics['content_tokens']) & 255) === 0) { $this->session->budget->guardDeadline(); }
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
            if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
            $runLength = strcspn($content, "\\()", $offset, min(16384, $length - $offset));
            if ($runLength > 0) {
                $this->session->budget->guardDeadline();
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
            if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
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
            if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
            $char = $content[$offset];
            if ($this->isPdfWhitespace($char) || $this->isPdfDelimiter($char)) {
                break;
            }
            $offset++;
        }

        return $this->session->encoding->decodePdfNameEscapes(substr($content, $start, $offset - $start));
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
            if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
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

    public function skipContentWhitespaceAndComments(string $content, int &$offset): void
    {
        $length = strlen($content);

        while ($offset < $length) {
            if (($offset & 1023) === 0) { $this->session->budget->guardDeadline(); }
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

    public function isPdfWhitespace(string $char): bool
    {
        return $char === " " || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\x0C" || $char === "\x00";
    }

    public function isPdfDelimiter(string $char): bool
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
}
