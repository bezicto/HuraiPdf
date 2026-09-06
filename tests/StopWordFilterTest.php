<?php

declare(strict_types=1);

namespace HuraiPdf\Tests;

use HuraiPdf\Filter\StopWordFilter;
use PHPUnit\Framework\TestCase;

final class StopWordFilterTest extends TestCase
{
    public function testChunksAreFilteredWithoutBuildingCombinedInput(): void
    {
        $filtered = iterator_to_array((new StopWordFilter())->filterChunks([
            4 => 'the invoice total',
            5 => 'and payment amount',
        ]));

        self::assertSame([4 => 'the invoice total', 5 => 'payment amount'], $filtered);
    }
}
