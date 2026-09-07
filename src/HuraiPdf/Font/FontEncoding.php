<?php

declare(strict_types=1);

namespace HuraiPdf\Font;

use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\PdfObject;
use HuraiPdf\Internal\Subsystem;

/** @internal */
final class FontEncoding extends Subsystem
{
    public function decodeDocumentString(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return $this->session->cmap->hexToUtf8(bin2hex(substr($bytes, 2)));
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) { return substr($bytes, 3); }
        $out = '';
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            if (($i & 1023) === 0) { $this->session->budget->guardDeadline(); }
            $this->session->budget->appendBoundedText($out, $this->decodePdfDocByte(ord($bytes[$i])));
        }
        return $out;
    }
    /**
     * @param array<int, PdfObject> $objects
     * @return array{
     *     encoding_name: string,
     *     differences: array<int, string>,
     *     is_multibyte: bool
     * }
     */
    public function parseFontEncodingData(int $fontObjectId, string $fontBody, array &$objects): array
    {
        $entries = $this->session->syntax->dictionaryEntries($fontBody);
        $subtype = $this->session->syntax->nameValue($entries['Subtype'] ?? '');
        $baseFont = strtolower($this->session->syntax->nameValue($entries['BaseFont'] ?? ''));
        $encodingName = match (true) {
            str_contains($baseFont, 'symbol') => 'SymbolEncoding',
            str_contains($baseFont, 'zapfdingbats') => 'ZapfDingbatsEncoding',
            $subtype === 'Type1' => 'StandardEncoding',
            default => 'WinAnsiEncoding',
        };
        $differences = [];
        $encoding = $this->session->resources->resolveValue($entries['Encoding'] ?? '', $objects);
        $name = $this->session->syntax->nameValue($encoding);
        if ($name !== '') {
            $encodingName = $name;
        } elseif (str_starts_with($encoding, '<<')) {
            $dictionary = $this->session->syntax->dictionaryEntries($encoding);
            $base = $this->session->resources->resolveValue($dictionary['BaseEncoding'] ?? '', $objects);
            $name = $this->session->syntax->nameValue($base);
            if ($name !== '') { $encodingName = $name; }
            $array = $this->session->resources->resolveValue($dictionary['Differences'] ?? '', $objects);
            if (str_starts_with($array, '[')) {
                $currentCode = null;
                foreach ($this->session->syntax->parsePdfArrayItems(substr($array, 1, -1)) as $token) {
                    if (preg_match('/^\d+$/', $token) === 1) {
                        $currentCode = (int) $token;
                    } elseif (($glyph = $this->session->syntax->nameValue($token)) !== '' && $currentCode !== null && $currentCode <= 255) {
                        $differences[$currentCode++] = $glyph;
                    }
                }
            }
        }
        $isMultibyte = $subtype === 'Type0' || stripos($encodingName, 'Identity') !== false || isset($entries['CIDToGIDMap']);
        return ['encoding_name' => $encodingName, 'differences' => $differences, 'is_multibyte' => $isMultibyte];
    }

    public function decodePdfNameEscapes(string $name): string
    {
        return (string) preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            static fn(array $m): string => chr(hexdec($m[1])),
            $name
        );
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
    public function decodeTextBytes(string $bytes, ?string $fontKey, array $fontMaps): string
    {
        if ($bytes === '') { return ''; }
        if (strlen($bytes) > $this->options->maxExtractedTextBytes) {
            throw PdfParseException::resourceLimitExceeded('Text operand exceeds maxExtractedTextBytes.');
        }

        if ($fontKey !== null && isset($fontMaps[$fontKey])) {
            $fontDef = $fontMaps[$fontKey];
            if ($fontDef['map'] !== []) {
                $mapped = $this->session->cmap->decodeWithFontMap($bytes, $fontDef['map'], $fontDef['max_code_bytes'], $fontDef);
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
    public function decodeWithFontEncodingFallback(string $bytes, array $fontDef): string
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
                        $this->session->budget->appendBoundedText($out, $mapped);
                        continue;
                    }
                }

                if ($code >= 32 && $code <= 126) {
                    $this->session->budget->appendBoundedText($out, chr($code));
                    continue;
                }

                $this->session->budget->appendBoundedText($out, $this->unicodeCodePointToUtf8($code));
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
                        $this->session->budget->appendBoundedText($out, $this->convertSingleByteRun($run, $encodingName));
                        $run = '';
                    }
                    $this->session->budget->appendBoundedText($out, $mapped);
                    continue;
                }
            }

            $run .= $bytes[$i];
        }

        if ($run !== '') {
            $this->session->budget->appendBoundedText($out, $this->convertSingleByteRun($run, $encodingName));
        }

        return $out;
    }

    private function convertSingleByteRun(string $bytes, string $encodingName): string
    {
        $table = match ($encodingName) {
            'StandardEncoding' => EncodingTables::STANDARD,
            'SymbolEncoding' => EncodingTables::SYMBOL,
            'ZapfDingbatsEncoding' => EncodingTables::ZAPF_DINGBATS,
            'MacRomanEncoding' => EncodingTables::MAC_ROMAN,
            default => EncodingTables::WIN_ANSI,
        };
        $out = '';
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            if (($i & 1023) === 0) { $this->session->budget->guardDeadline(); }
            $code = ord($bytes[$i]);
            $text = $encodingName === 'PDFDocEncoding'
                ? $this->decodePdfDocByte($code) : $this->unicodeCodePointToUtf8($table[$code]);
            $this->session->budget->appendBoundedText($out, $text);
        }
        return $out;
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

        return in_array($code, [0x7F, 0x9F, 0xAD], true) ? '' : $this->unicodeCodePointToUtf8($code);
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

                $this->session->budget->appendBoundedText($out, $this->glyphNameToUnicode($part));
            }
            return $out;
        }

        if (preg_match('/^uni([0-9A-Fa-f]{4,})$/', $normalizedName, $uniMatch) === 1) {
            $hex = strtoupper($uniMatch[1]);
            if (strlen($hex) % 4 === 0) {
                $out = '';
                for ($i = 0; $i < strlen($hex); $i += 4) {
                    $this->session->budget->appendBoundedText($out, $this->unicodeCodePointToUtf8(hexdec(substr($hex, $i, 4))));
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

    public function unicodeCodePointToUtf8(int $codePoint): string
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

    public function convertEncoding(string $bytes, string $from): string
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
}
