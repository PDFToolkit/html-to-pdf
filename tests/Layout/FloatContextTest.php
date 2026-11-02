<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Layout\FloatContext;
use PDF\HtmlToPdf\Layout\LineExclusions;
use PHPUnit\Framework\TestCase;

final class FloatContextTest extends TestCase
{
    public function testIndependentHeightsAllowTheNextFloatBelowTheShorterNeighbour(): void
    {
        $context = new FloatContext(1000);
        $first = $context->place('left', 60, 100, 0, 0, 200);
        $second = $context->place('right', 100, 40, 0, 0, 200);
        $third = $context->place('left', 100, 20, 0, 0, 200);
        self::assertSame([0.0, 0.0], [$first->x, $first->top]);
        self::assertSame([100.0, 0.0], [$second->x, $second->top]);
        self::assertSame([60.0, 40.0], [$third->x, $third->top]);
        self::assertSame(100.0, $context->clear('left', 0));
        self::assertSame(40.0, $context->clear('right', 0));
        self::assertSame(100.0, $context->clear('both', 0));
    }

    public function testAnUnbreakableInlineItemCanDescendBeforeTheFullWidthReturns(): void
    {
        $context = new FloatContext(1000);
        $context->place('left', 60, 40, 0, 0, 200);
        $context->place('right', 100, 20, 0, 0, 200);
        $exclusions = new LineExclusions($context, 0, 200);
        $first = $exclusions->space(0, 10);
        $wide = $exclusions->space(0, 10, 80);
        $below = $exclusions->space(40, 10);
        self::assertSame([60.0, 40.0, 0.0], [$first->x, $first->width, $first->top]);
        self::assertSame([60.0, 140.0, 20.0], [$wide->x, $wide->width, $wide->top]);
        self::assertSame([0.0, 200.0, 40.0], [$below->x, $below->width, $below->top]);
    }

    public function testAnOversizedFloatFailsInsteadOfSearchingForever(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new FloatContext(100))->place('left', 201, 10, 0, 0, 200);
    }

    public function testRepeatedExclusionQueriesConsumeTheWorkBudget(): void
    {
        $context = new FloatContext(5);
        $context->place('left', 10, 10, 0, 0, 200);
        $this->expectException(LimitExceeded::class);
        for ($index = 0; $index < 10; ++$index) {
            $context->space(0, 200, 0, 10);
        }
    }

    public function testNestedContextsShareTheirCumulativeWorkBudget(): void
    {
        $context = new FloatContext(5);
        $nested = $context->nested();
        $context->place('left', 10, 10, 0, 0, 200);
        $nested->place('right', 10, 10, 0, 0, 200);
        $this->expectException(LimitExceeded::class);
        $nested->space(0, 200, 0, 10);
    }
}
