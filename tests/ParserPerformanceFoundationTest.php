<?php

declare(strict_types=1);

namespace HuraiPdf\Tests;

use HuraiPdf\Parser;
use HuraiPdf\ParserOptions;
use HuraiPdf\Exception\PdfParseException;
use HuraiPdf\Tests\Support\PdfFixtureFactory;
use PHPUnit\Framework\TestCase;

final class ParserPerformanceFoundationTest extends TestCase
{
    public function testParserOptionsRejectInvalidPerformanceThresholds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ParserOptions(objectChunkSize: 0);
    }

    public function testParseContentCacheIsIsolatedBetweenDocuments(): void
    {
        $parser = new Parser();

        self::assertSame('A', $parser->parseContent(PdfFixtureFactory::cmapPdf('0041'))->getText());
        self::assertSame('B', $parser->parseContent(PdfFixtureFactory::cmapPdf('0042'))->getText());
    }

    public function testWinAnsiTextStillDecodesAfterBulkConversionChange(): void
    {
        $pdf = PdfFixtureFactory::textPdf(["Caf\xE9"]);

        self::assertSame('Café', (new Parser())->parseContent($pdf)->getText());
    }

    public function testLazyPageRangeDoesNotLoadUnrelatedObjects(): void
    {
        $pages = array_map(static fn(int $page): string => 'Page ' . $page, range(1, 20));
        $pdf = PdfFixtureFactory::textPdf($pages, 150, 4096);
        $path = $this->writeTemporaryPdf($pdf);

        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            $document = $parser->parseFile($path, 10, 11);
            $metrics = $parser->getLastMetrics();

            self::assertSame('Page 10 Page 11', $document->getText());
            self::assertTrue($metrics['streaming_path']);
            self::assertLessThan($metrics['objects_indexed'], $metrics['objects_loaded']);
            self::assertLessThan(strlen($pdf), $metrics['object_bytes_read']);
        } finally {
            @unlink($path);
        }
    }

    public function testTraditionalXrefLargerThanSixtyFourKilobytesStaysLazy(): void
    {
        $pdf = PdfFixtureFactory::textPdf(['Large xref'], 3500, 8);
        $path = $this->writeTemporaryPdf($pdf);

        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            self::assertSame('Large xref', $parser->parseFile($path)->getText());
            $metrics = $parser->getLastMetrics();

            self::assertTrue($metrics['streaming_path']);
            self::assertGreaterThan(3500, $metrics['objects_indexed']);
            self::assertLessThan(20, $metrics['objects_loaded']);
        } finally {
            @unlink($path);
        }
    }

    public function testLazyReaderHandlesXrefStreams(): void
    {
        $this->assertStreamingFixture(PdfFixtureFactory::xrefStreamPdf(), 'Xref stream');
    }

    public function testLazyReaderHandlesCompressedObjectStreams(): void
    {
        $this->assertStreamingFixture(PdfFixtureFactory::objectStreamPdf(), 'Object stream');
    }

    public function testLazyReaderMergesIncrementalXrefRevisions(): void
    {
        $pdf = PdfFixtureFactory::incrementalPdf();
        $path = $this->writeTemporaryPdf($pdf);
        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            self::assertSame('Updated', $parser->parseFile($path)->getText());
            self::assertSame(2, $parser->getLastMetrics()['xref_sections']);
        } finally {
            @unlink($path);
        }
    }

    public function testLazyAndInMemoryPathsProduceTheSameText(): void
    {
        $fixtures = [
            PdfFixtureFactory::textPdf(['One', 'Two']),
            PdfFixtureFactory::xrefStreamPdf(),
            PdfFixtureFactory::objectStreamPdf(),
            PdfFixtureFactory::incrementalPdf(),
        ];

        foreach ($fixtures as $pdf) {
            $expected = (new Parser())->parseContent($pdf)->getText();
            $path = $this->writeTemporaryPdf($pdf);
            try {
                $actual = (new Parser(new ParserOptions(streamingThreshold: 1)))->parseFile($path)->getText();
                self::assertSame($expected, $actual);
            } finally {
                @unlink($path);
            }
        }
    }

    public function testLazyReaderLoadsInheritedResources(): void
    {
        $this->assertStreamingFixture(PdfFixtureFactory::inheritedResourcesPdf(), 'Inherited resources');
    }

    public function testUnsupportedXrefStructureUsesCompatibleFallback(): void
    {
        $pdf = PdfFixtureFactory::pdfWithoutXref('Fallback');
        $path = $this->writeTemporaryPdf($pdf);
        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            self::assertSame('Fallback', $parser->parseFile($path)->getText());
            self::assertFalse($parser->getLastMetrics()['streaming_path']);
        } finally {
            @unlink($path);
        }
    }

    public function testIncrementalPageAndSinkApis(): void
    {
        $pdf = PdfFixtureFactory::textPdf(['First', 'Second', 'Third'], 50, 128);
        $path = $this->writeTemporaryPdf($pdf);
        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            $pages = iterator_to_array($parser->parseFilePages($path, 2, 3), false);
            self::assertSame(['Second', 'Third'], array_map(static fn($page): string => $page->getText(), $pages));
            self::assertSame(2, $parser->getLastMetadata()['page_count']);

            $seen = [];
            $result = $parser->extractFile($path, static function ($page) use (&$seen): void {
                $seen[] = $page->getText();
            }, 1, 2);
            self::assertSame(['First', 'Second'], $seen);
            self::assertSame(2, $result['metadata']['page_count']);
            self::assertGreaterThan(0, $result['metrics']['duration_ms']);
        } finally {
            @unlink($path);
        }
    }

    public function testEarlyGeneratorTerminationFinalizesMetadata(): void
    {
        $pdf = PdfFixtureFactory::textPdf(['First', 'Second', 'Third']);
        $path = $this->writeTemporaryPdf($pdf);
        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            $generator = $parser->parseFilePages($path);
            foreach ($generator as $page) {
                self::assertSame('First', $page->getText());
                break;
            }
            unset($generator);
            self::assertSame(1, $parser->getLastMetadata()['page_count']);
            self::assertGreaterThan(0, $parser->getLastMetrics()['duration_ms']);
        } finally {
            @unlink($path);
        }
    }

    public function testObjectBudgetFailsDeterministically(): void
    {
        $parser = new Parser(new ParserOptions(maxObjects: 5));
        try {
            $parser->parseContent(PdfFixtureFactory::textPdf(['Budget'], 10));
            self::fail('Expected object budget failure.');
        } catch (PdfParseException $exception) {
            self::assertSame(PdfParseException::RESOURCE_LIMIT_EXCEEDED, $exception->getCode());
            self::assertStringContainsString('maxObjects', $exception->getMessage());
        }
    }

    public function testDecodedByteBudgetFailsDeterministically(): void
    {
        $parser = new Parser(new ParserOptions(maxDecodedBytesTotal: 10));
        $this->expectExceptionCode(PdfParseException::RESOURCE_LIMIT_EXCEEDED);
        $this->expectExceptionMessage('maxDecodedBytesTotal');
        $parser->parseContent(PdfFixtureFactory::textPdf(['This content is longer than ten bytes']));
    }

    public function testContentOperatorBudgetFailsDeterministically(): void
    {
        $parser = new Parser(new ParserOptions(maxContentOperators: 2));
        $this->expectExceptionCode(PdfParseException::RESOURCE_LIMIT_EXCEEDED);
        $this->expectExceptionMessage('maxContentOperators');
        $parser->parseContent(PdfFixtureFactory::textPdf(['Operators']));
    }

    public function testBitBufferedLzwDecoderPreservesText(): void
    {
        self::assertSame('LZW text', (new Parser())->parseContent(PdfFixtureFactory::lzwPdf())->getText());
    }

    public function testRepeatedFormStreamIsDecodedOnce(): void
    {
        $parser = new Parser();
        self::assertSame('Stamp Stamp', $parser->parseContent(PdfFixtureFactory::repeatedFormPdf())->getText());
        self::assertSame(2, $parser->getLastMetrics()['decoded_streams']);
    }

    public function testWarningsAreBounded(): void
    {
        $document = (new Parser(new ParserOptions(maxWarnings: 2)))
            ->parseContent(PdfFixtureFactory::repeatedWarningPdf());

        self::assertCount(2, $document->getWarnings());
        self::assertSame('Additional warnings truncated.', $document->getWarnings()[1]);
    }

    public function testRunLengthExpansionHonorsStreamBudget(): void
    {
        $parser = new Parser(new ParserOptions(maxStreamBytes: 64));
        $this->expectExceptionCode(PdfParseException::RESOURCE_LIMIT_EXCEEDED);
        $this->expectExceptionMessage('RunLength stream');
        $parser->parseContent(PdfFixtureFactory::oversizedRunLengthPdf());
    }

    public function testPageBudgetFailsDeterministically(): void
    {
        $parser = new Parser(new ParserOptions(maxPages: 1));
        $this->expectExceptionCode(PdfParseException::RESOURCE_LIMIT_EXCEEDED);
        $this->expectExceptionMessage('maxPages');
        $parser->parseContent(PdfFixtureFactory::textPdf(['One', 'Two']));
    }

    public function testDeadlineFailsDeterministically(): void
    {
        $parser = new Parser(new ParserOptions(deadlineSeconds: 0.000000001));
        $this->expectExceptionCode(PdfParseException::RESOURCE_LIMIT_EXCEEDED);
        $this->expectExceptionMessage('deadline');
        $parser->parseContent(PdfFixtureFactory::textPdf(['Deadline']));
    }

    private function assertStreamingFixture(string $pdf, string $expectedText): void
    {
        $path = $this->writeTemporaryPdf($pdf);
        try {
            $parser = new Parser(new ParserOptions(streamingThreshold: 1));
            self::assertSame($expectedText, $parser->parseFile($path)->getText());
            self::assertTrue($parser->getLastMetrics()['streaming_path']);
        } finally {
            @unlink($path);
        }
    }

    private function writeTemporaryPdf(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hurai-pdf-test-');
        self::assertNotFalse($path);
        self::assertSame(strlen($pdf), file_put_contents($path, $pdf));
        return $path;
    }
}
