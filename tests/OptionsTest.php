<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testStrictPolicyRejectsUnsupportedBehavior(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Diagnostics())->unsupported('css.property', 'Grid is not implemented.');
    }

    public function testReportPolicyIsBoundedAndPreservesSourceIdentity(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report, 1);
        $diagnostics->unsupported('css.property', 'Grid is not implemented.', 'n1');
        self::assertSame('n1', $diagnostics->all()[0]->nodeId);
        $this->expectException(LimitExceeded::class);
        $diagnostics->add('another', 'Another issue.');
    }

    public function testUnusablePageGeometryFailsBeforeLayout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageSettings(margin: new Edges(500, 500, 500, 500));
    }

    public function testNonfiniteGeometryFailsBeforeLayout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageSettings(height: INF);
    }

    public function testLimitsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConversionLimits(maxPages: 0);
    }
}
