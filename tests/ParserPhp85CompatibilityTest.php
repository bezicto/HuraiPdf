<?php

declare(strict_types=1);

namespace HuraiPdf\Tests;

use ErrorException;
use HuraiPdf\Document;
use HuraiPdf\Parser;
use HuraiPdf\Tests\Support\PdfFixtureFactory;
use PHPUnit\Framework\TestCase;

final class ParserPhp85CompatibilityTest extends TestCase
{
    public function testOutOfRangeOctalEscapeIsExplicitlyConstrainedToOneByte(): void
    {
        $document = $this->parseWithoutPhpDiagnostics(
            $this->contentPdf('BT (\\777) Tj ET')
        );

        self::assertSame('ÿ', $document->getText());
    }

    public function testOversizedCMapSourceCodeIsIgnoredWithoutIntegerCastWarning(): void
    {
        $cmap = "1 beginbfrange\n<FFFFFFFFFFFFFFFF> <FFFFFFFFFFFFFFFF> <0041>\nendbfrange";
        $document = $this->parseWithoutPhpDiagnostics($this->contentPdf('BT /F1 12 Tf <00> Tj ET', $cmap));

        self::assertSame(1, $document->getPageCount());
    }

    public function testWideCMapDestinationIsIncrementedWithoutIntegerCastWarning(): void
    {
        $cmap = "1 beginbfrange\n<00> <01> <FFFFFFFFFFFFFFFF>\nendbfrange";
        $document = $this->parseWithoutPhpDiagnostics($this->contentPdf('BT /F1 12 Tf <0001> Tj ET', $cmap));

        self::assertSame(1, $document->getPageCount());
    }

    public function testSequentialCMapRangeStillDecodesNormally(): void
    {
        $cmap = "1 beginbfrange\n<00> <02> <0041>\nendbfrange";
        $document = $this->parseWithoutPhpDiagnostics($this->contentPdf('BT /F1 12 Tf <000102> Tj ET', $cmap));

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

    private function contentPdf(string $content, ?string $cmap = null): string
    {
        $resources = $cmap === null ? '<< >>' : '<< /Font << /F1 5 0 R >> >>';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources ' . $resources . ' >>',
            4 => '<< /Length ' . strlen($content) . ">> stream\n{$content}\nendstream",
        ];
        if ($cmap !== null) {
            $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 6 0 R >>';
            $objects[6] = '<< /Length ' . strlen($cmap) . ">> stream\n{$cmap}\nendstream";
        }
        return PdfFixtureFactory::build($objects);
    }
}
