<?php

declare(strict_types=1);

namespace HuraiPdf\Font;

use HuraiPdf\Internal\Subsystem;

/** @internal */
final class CMapParser extends Subsystem
{
    /**
     * @return array{map: array<string, string>, max_code_bytes: int}
     */
    public function parseToUnicodeCMap(string $cmap): array
    {
        $this->session->budget->guardDeadline();
        if (strlen($cmap) > $this->options->maxCMapSize) {
            $cmap = substr($cmap, 0, $this->options->maxCMapSize);
        }
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
                $this->session->budget->guardDeadline();
                $offset = $entry[0][1] + strlen($entry[0][0]);
                $source = strtoupper($entry[1][0]);
                $start = $this->parseCMapSourceCode($source);
                if ($start === null || strlen($source) % 2 !== 0) { continue; }
                if (!$range) {
                    $destination = $entry[2][0];
                    $this->session->budget->accountCMapEntry(strlen($source) + strlen($destination));
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
                        $this->session->budget->accountCMapEntry(strlen($source) + strlen($destination));
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
    public function decodeWithFontMap(string $bytes, array $map, int $maxCodeBytes, ?array $fontDef = null): string
    {
        $hex = strtoupper(bin2hex($bytes));
        $length = strlen($hex);
        $cursor = 0;
        $out = '';
        $preferMappedOnly = $fontDef !== null
            && ($fontDef['map'] ?? []) !== []
            && (($fontDef['is_multibyte'] ?? false) || ($fontDef['has_tounicode'] ?? false));

        $iterations = 0;
        while ($cursor < $length) {
            if ((++$iterations & 1023) === 0) { $this->session->budget->guardDeadline(); }
            $matched = false;

            for ($byteWidth = $maxCodeBytes; $byteWidth >= 1; $byteWidth--) {
                $charWidth = $byteWidth * 2;
                if ($cursor + $charWidth > $length) {
                    continue;
                }

                $code = substr($hex, $cursor, $charWidth);
                if (isset($map[$code])) {
                    $this->session->budget->appendBoundedText($out, $map[$code]);
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
                $fallbackText = $this->session->encoding->decodeWithFontEncodingFallback($fallbackBytes, $fontDef);
                if ($fallbackText !== '') {
                    $this->session->budget->appendBoundedText($out, $fallbackText);
                    $cursor += $stepBytes * 2;
                    continue;
                }
            }

            if ($stepBytes === 2 && str_starts_with($fallbackHex, '00')) {
                $this->session->budget->appendBoundedText($out, chr(hexdec(substr($fallbackHex, 2, 2))));
                $cursor += 4;
                continue;
            }

            $firstByte = chr(hexdec(substr($fallbackHex, 0, 2)));
            $converted = $this->session->encoding->convertEncoding($firstByte, 'Windows-1252');
            $this->session->budget->appendBoundedText($out, $converted !== '' ? $converted : $firstByte);
            $cursor += 2;
        }

        return $out;
    }

    public function hexToUtf8(string $hex): string
    {
        if ($hex === '' || strlen($hex) % 4 !== 0) { return ''; }
        $bytes = @hex2bin($hex);
        if ($bytes === false) { return ''; }
        $out = '';
        for ($i = 0, $length = strlen($bytes); $i < $length; $i += 2) {
            if (($i & 4095) === 0) { $this->session->budget->guardDeadline(); }
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
            $this->session->budget->appendBoundedText($out, $this->session->encoding->unicodeCodePointToUtf8($code));
        }
        return $out;
    }
}
