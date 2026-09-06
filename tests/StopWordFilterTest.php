<?php

declare(strict_types=1);

namespace HuraiPdf\Tests;

use HuraiPdf\Filter\StopWordFilter;
use PHPUnit\Framework\TestCase;

final class StopWordFilterTest extends TestCase
{
    public function testNumbersRemainAvailableByDefault(): void
    {
        self::assertSame(
            'widget 2026 batch42code room7',
            (new StopWordFilter())->filter('Widget 2026 Batch42Code Room7')
        );
    }

    public function testNumbersCanBeExcludedDuringTokenization(): void
    {
        self::assertSame(
            'widget batch code room',
            (new StopWordFilter())->filter('Widget 2026 Batch42Code Room7', true)
        );
    }

    public function testNumericOnlyTextProducesNoOutputWhenNumbersAreExcluded(): void
    {
        self::assertSame('', (new StopWordFilter())->filter('123 45.67 890', true));
    }

    public function testChunksAreFilteredWithoutBuildingCombinedInput(): void
    {
        $filtered = iterator_to_array((new StopWordFilter())->filterChunks([
            4 => 'the invoice total',
            5 => 'and payment amount',
        ]));

        self::assertSame([4 => 'the invoice total', 5 => 'payment amount'], $filtered);
    }

    public function testChunkFilteringPropagatesTheExcludeNumbersOption(): void
    {
        $filtered = iterator_to_array((new StopWordFilter())->filterChunks([
            4 => 'Invoice 2026',
            5 => '123 456',
            6 => 'Batch42Code',
        ], true));

        self::assertSame([4 => 'invoice', 6 => 'batch code'], $filtered);
    }
}
