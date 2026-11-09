<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\TextSpacing;
use PHPUnit\Framework\TestCase;

final class ListTextSpacingTest extends TestCase
{
    private static FontFace $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    public function testMarkerMeasurementAndDrawingUseInheritedSpacing(): void
    {
        $texts = $this->texts('<ol start="10" style="letter-spacing:3pt;word-spacing:4pt"><li>alpha</li></ol>');
        self::assertSame(['10.', 'alpha'], array_column($texts, 'text'));
        self::assertEquals(new TextSpacing(3, 4), $texts[0]->spacing);
        self::assertSame($texts[0]->spacing, $texts[1]->spacing);
        self::assertEqualsWithDelta(self::$font->measure('10. ', 12) + 16, $texts[1]->x, 0.000001);
        self::assertEqualsWithDelta(self::$font->measure('10.', 12) + 9, self::$font->measure($texts[0]->text, 12, $texts[0]->spacing), 0.000001);
    }

    public function testNegativeMarkerSpacingRetainsTheMinimumGutter(): void
    {
        $texts = $this->texts('<ol start="10" style="letter-spacing:-.5pt;word-spacing:-1pt"><li>alpha</li></ol>');
        self::assertEquals(new TextSpacing(-0.5, -1), $texts[0]->spacing);
        self::assertSame(18.0, $texts[1]->x);
    }

    public function testPercentageIndentUsesTheListContentWidthDespiteDifferentMarkers(): void
    {
        $texts = $this->texts('<ol style="text-indent:10%"><li value="10" style="width:180pt">alpha</li><li value="1000" style="width:180pt">beta</li></ol>');
        self::assertSame(['10.', 'alpha', '1000.', 'beta'], array_column($texts, 'text'));
        self::assertEqualsWithDelta(18, $texts[1]->x - self::$font->measure('10. ', 12), 0.000001);
        self::assertEqualsWithDelta(18, $texts[3]->x - self::$font->measure('1000. ', 12), 0.000001);
    }

    public function testNegativePercentageIndentKeepsTheFullListContentBasis(): void
    {
        $texts = $this->texts('<ol start="10"><li style="width:180pt;text-indent:-10%">alpha<br>beta</li></ol>');
        self::assertEqualsWithDelta(self::$font->measure('10. ', 12) - 18, $texts[1]->x, 0.000001);
        self::assertEqualsWithDelta(self::$font->measure('10. ', 12), $texts[2]->x, 0.000001);
    }

    public function testListBasisDoesNotLeakIntoDescendantBlocksOrRestartAfterThem(): void
    {
        $texts = $this->texts('<ol start="10"><li style="width:180pt;text-indent:10%">alpha<div style="width:100pt">beta</div>gamma</li></ol>');
        $gutter = self::$font->measure('10. ', 12);
        self::assertSame(['10.', 'alpha', 'beta', 'gamma'], array_column($texts, 'text'));
        self::assertEqualsWithDelta($gutter + 18, $texts[1]->x, 0.000001);
        self::assertEqualsWithDelta($gutter + 10, $texts[2]->x, 0.000001);
        self::assertEqualsWithDelta($gutter, $texts[3]->x, 0.000001);
    }

    public function testNestedListEstablishesItsOwnPercentageBasis(): void
    {
        $texts = $this->texts('<ol start="10"><li style="width:180pt;text-indent:10%">alpha<ol start="20"><li style="width:100pt">beta</li></ol></li></ol>');
        self::assertSame(['10.', 'alpha', '20.', 'beta'], array_column($texts, 'text'));
        $outerGutter = self::$font->measure('10. ', 12);
        $innerGutter = self::$font->measure('20. ', 12);
        self::assertEqualsWithDelta($outerGutter + 18, $texts[1]->x, 0.000001);
        self::assertEqualsWithDelta($outerGutter + $innerGutter + 10, $texts[3]->x, 0.000001);
    }

    public function testEmptyListItemKeepsItsSpacedMarker(): void
    {
        $texts = $this->texts('<ol start="10"><li style="width:180pt;text-indent:10%;letter-spacing:2pt"></li></ol>');
        self::assertCount(1, $texts);
        self::assertSame('10.', $texts[0]->text);
        self::assertEquals(new TextSpacing(2), $texts[0]->spacing);
    }

    /** @return list<Text> */
    private function texts(string $html): array
    {
        $options = new ConversionOptions(new FontSet(self::$font), new PageSettings(300, 400, new Edges()));
        $diagnostics = new Diagnostics();
        $document = (new HtmlCompiler())->parse(new HtmlSource('<style>*{margin:0;padding:0}body{font-size:12pt;line-height:16pt}</style>' . $html), $options, $diagnostics);
        $layout = (new LayoutEngine())->layout($document, $options, $diagnostics);
        self::assertSame([], $diagnostics->all());
        return array_values(array_filter($layout->pages[0]->commands, static fn($command): bool => $command instanceof Text));
    }
}
