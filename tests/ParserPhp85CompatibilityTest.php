<?php

declare(strict_types=1);

namespace HuraiPdf\Tests;

use ErrorException;
use HuraiPdf\Document;
use HuraiPdf\Parser;
use PHPUnit\Framework\TestCase;

final class ParserPhp85CompatibilityTest extends TestCase
{
    public function testOutOfRangeOctalEscapeIsExplicitlyConstrainedToOneByte(): void
    {
        $document = $this->parseWithoutPhpDiagnostics(
            $this->buildPdf('BT (\\777) Tj ET')
        );

        self::assertSame('ÿ', $document->getText());
    }

    public function testOversizedCMapSourceCodeIsIgnoredWithoutIntegerCastWarning(): void
    {
        $cmap = "1 beginbfrange\n<FFFFFFFFFFFFFFFF> <FFFFFFFFFFFFFFFF> <0041>\nendbfrange";

        $document = $this->parseWithoutPhpDiagnostics(
            $this->buildPdf('BT /F1 12 Tf <00> Tj ET', $cmap)
        );

        self::assertSame(1, $document->getPageCount());
    }

    public function testWideCMapDestinationIsIncrementedWithoutIntegerCastWarning(): void
    {
        $cmap = "1 beginbfrange\n<00> <01> <FFFFFFFFFFFFFFFF>\nendbfrange";

        $document = $this->parseWithoutPhpDiagnostics(
            $this->buildPdf('BT /F1 12 Tf <0001> Tj ET', $cmap)
        );

        self::assertSame(1, $document->getPageCount());
    }

    public function testSequentialCMapRangeStillDecodesNormally(): void
    {
        $cmap = "1 beginbfrange\n<00> <02> <0041>\nendbfrange";

        $document = $this->parseWithoutPhpDiagnostics(
            $this->buildPdf('BT /F1 12 Tf <000102> Tj ET', $cmap)
        );

        self::assertSame('ABC', $document->getText());
    }

    private function parseWithoutPhpDiagnostics(string $pdf): Document
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        );

        try {
            return (new Parser())->parseContent($pdf);
        } finally {
            restore_error_handler();
        }
    }

    private function buildPdf(string $contentStream, ?string $cmapStream = null): string
    {
        $resources = $cmapStream === null ? '<< >>' : '<< /Font << /F1 5 0 R >> >>';

        $pdf = "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources {$resources} >> endobj\n"
            . '4 0 obj << /Length ' . strlen($contentStream) . ">> stream\n"
            . $contentStream . "\nendstream endobj\n";

        if ($cmapStream !== null) {
            $pdf .= "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 6 0 R >> endobj\n"
                . '6 0 obj << /Length ' . strlen($cmapStream) . ">> stream\n"
                . $cmapStream . "\nendstream endobj\n";
        }

        return $pdf;
    }
}
