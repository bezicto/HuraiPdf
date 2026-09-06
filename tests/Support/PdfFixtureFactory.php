<?php

declare(strict_types=1);

namespace HuraiPdf\Tests\Support;

final class PdfFixtureFactory
{
    /**
     * @param string[] $pageTexts
     */
    public static function textPdf(array $pageTexts, int $dummyObjects = 0, int $dummyBytes = 32): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pageIds = [];
        $nextId = 4;
        foreach ($pageTexts as $text) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $stream = 'BT /F1 12 Tf (' . self::escapeLiteral($text) . ') Tj ET';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /Contents ' . $contentId
                . ' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
            $objects[$contentId] = '<< /Length ' . strlen($stream) . ">> stream\n"
                . $stream . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['
            . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds))
            . '] /Count ' . count($pageIds) . ' >>';

        for ($i = 0; $i < $dummyObjects; $i++) {
            $objects[$nextId++] = '<< /Ignored true >> ' . str_repeat('x', $dummyBytes);
        }

        return self::build($objects);
    }

    public static function cmapPdf(string $destinationHex): string
    {
        $content = 'BT /F1 12 Tf <00> Tj ET';
        $cmap = "1 beginbfchar\n<00> <{$destinationHex}>\nendbfchar";

        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length ' . strlen($content) . ">> stream\n{$content}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 6 0 R >>',
            6 => '<< /Length ' . strlen($cmap) . ">> stream\n{$cmap}\nendstream",
        ]);
    }

    public static function xrefStreamPdf(string $text = 'Xref stream'): string
    {
        $content = 'BT /F1 12 Tf (' . self::escapeLiteral($text) . ') Tj ET';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [5 0 R] /Count 1 >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Length ' . strlen($content) . ">> stream\n{$content}\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 3 0 R >> >> >>',
        ];

        return self::buildXrefStream($objects, [], 1, 6);
    }

    public static function objectStreamPdf(string $text = 'Object stream'): string
    {
        $content = 'BT /F1 12 Tf (' . self::escapeLiteral($text) . ') Tj ET';
        $compressedBodies = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [5 0 R] /Count 1 >>',
            5 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /Font << /F1 3 0 R >> >> >>',
        ];
        $objectData = '';
        $header = '';
        $compressedIndex = [];
        $position = 0;
        foreach ($compressedBodies as $id => $body) {
            $compressedIndex[$id] = count($compressedIndex);
            $header .= $id . ' ' . $position . ' ';
            $objectData .= $body . ' ';
            $position = strlen($objectData);
        }
        $decodedStream = $header . $objectData;
        $encodedStream = gzcompress($decodedStream);
        if ($encodedStream === false) {
            throw new \RuntimeException('Unable to encode object stream fixture.');
        }

        $objects = [
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Length ' . strlen($content) . ">> stream\n{$content}\nendstream",
            7 => '<< /Type /ObjStm /N 3 /First ' . strlen($header) . ' /Filter /FlateDecode /Length '
                . strlen($encodedStream) . ">> stream\n" . $encodedStream . "\nendstream",
        ];

        return self::buildXrefStream($objects, $compressedIndex, 1, 8, 7);
    }

    public static function incrementalPdf(): string
    {
        $base = self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 20 >> stream\nBT (Old) Tj ET\nendstream",
        ]);
        preg_match('/startxref\s+(\d+)/', $base, $match);
        $previousXref = (int) ($match[1] ?? 0);
        $updatedStream = 'BT (Updated) Tj ET';
        $updatedOffset = strlen($base);
        $base .= "4 0 obj\n<< /Length " . strlen($updatedStream) . ">> stream\n{$updatedStream}\nendstream\nendobj\n";
        $xrefOffset = strlen($base);
        $base .= "xref\n4 1\n" . sprintf("%010d 00000 n \n", $updatedOffset);
        $base .= "trailer << /Size 5 /Root 1 0 R /Prev {$previousXref} >>\n";
        $base .= "startxref\n{$xrefOffset}\n%%EOF\n";
        return $base;
    }

    public static function inheritedResourcesPdf(): string
    {
        $content = 'BT /F1 12 Tf (Inherited resources) Tj ET';
        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 /Resources << /Font << /F1 3 0 R >> >> >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
            5 => '<< /Length ' . strlen($content) . ">> stream\n{$content}\nendstream",
        ]);
    }

    public static function pdfWithoutXref(string $text): string
    {
        $content = 'BT (' . self::escapeLiteral($text) . ') Tj ET';
        return "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
            . '4 0 obj << /Length ' . strlen($content) . ">> stream\n{$content}\nendstream endobj\n";
    }

    public static function lzwPdf(string $text = 'LZW text'): string
    {
        $content = 'BT (' . self::escapeLiteral($text) . ') Tj ET';
        $codes = array_merge([256], array_map('ord', str_split($content)), [257]);
        $encoded = self::packNineBitCodes($codes);

        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => '<< /Filter /LZWDecode /Length ' . strlen($encoded) . ">> stream\n{$encoded}\nendstream",
        ]);
    }

    public static function repeatedFormPdf(): string
    {
        $pageContent = '/Fm Do /Fm Do';
        $formContent = 'BT /F1 12 Tf (Stamp) Tj ET';
        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << /XObject << /Fm 6 0 R >> >> >>',
            4 => '<< /Length ' . strlen($pageContent) . ">> stream\n{$pageContent}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /XObject /Subtype /Form /Resources << /Font << /F1 5 0 R >> >> /Length '
                . strlen($formContent) . ">> stream\n{$formContent}\nendstream",
        ]);
    }

    public static function repeatedWarningPdf(): string
    {
        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents [4 0 R 5 0 R 6 0 R] >>',
            4 => "<< /Filter /Unsupported /Length 1 >> stream\nx\nendstream",
            5 => "<< /Filter /Unsupported /Length 1 >> stream\nx\nendstream",
            6 => "<< /Filter /Unsupported /Length 1 >> stream\nx\nendstream",
        ]);
    }

    public static function oversizedRunLengthPdf(): string
    {
        $encoded = chr(129) . 'A' . chr(128);
        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => '<< /Filter /RunLengthDecode /Length ' . strlen($encoded) . ">> stream\n{$encoded}\nendstream",
        ]);
    }

    /**
     * @param array<int, string> $objects
     */
    public static function build(array $objects, int $rootId = 1): string
    {
        ksort($objects, SORT_NUMERIC);
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maximumId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maximumId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maximumId; $id++) {
            if (isset($offsets[$id])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }
        $pdf .= 'trailer << /Size ' . ($maximumId + 1) . ' /Root ' . $rootId . " 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param array<int, string> $objects
     * @param array<int, int> $compressedIndexes
     */
    private static function buildXrefStream(
        array $objects,
        array $compressedIndexes,
        int $rootId,
        int $xrefObjectId,
        int $objectStreamId = 0
    ): string {
        ksort($objects, SORT_NUMERIC);
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $offsets[$xrefObjectId] = $xrefOffset;
        $size = $xrefObjectId + 1;
        $xrefData = '';
        for ($id = 0; $id < $size; $id++) {
            if (isset($compressedIndexes[$id])) {
                $xrefData .= pack('CNn', 2, $objectStreamId, $compressedIndexes[$id]);
            } elseif (isset($offsets[$id])) {
                $xrefData .= pack('CNn', 1, $offsets[$id], 0);
            } else {
                $xrefData .= pack('CNn', 0, 0, $id === 0 ? 65535 : 0);
            }
        }
        $pdf .= $xrefObjectId . " 0 obj\n<< /Type /XRef /Size {$size} /Root {$rootId} 0 R"
            . ' /W [1 4 2] /Length ' . strlen($xrefData) . ">> stream\n"
            . $xrefData . "\nendstream\nendobj\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";
        return $pdf;
    }

    private static function escapeLiteral(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** @param int[] $codes */
    private static function packNineBitCodes(array $codes): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach ($codes as $code) {
            $buffer = ($buffer << 9) | $code;
            $bits += 9;
            while ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
                $buffer = $bits === 0 ? 0 : $buffer & ((1 << $bits) - 1);
            }
        }
        if ($bits > 0) {
            $out .= chr(($buffer << (8 - $bits)) & 0xFF);
        }
        return $out;
    }
}
