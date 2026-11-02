<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Model\HtmlDocument;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\Length;
use PHPUnit\Framework\TestCase;

final class FloatingLayoutTest extends TestCase
{
    private static FontFace $font;
    private int $sequence = 0;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    public function testOppositeFloatsShareARowAndAnOversizedSuccessorMovesDown(): void
    {
        $red = $this->box($this->style(float: 'left', width: new Length(100), height: new Length(50), background: new Color(1, 0, 0)));
        $green = $this->box($this->style(float: 'right', width: new Length(100), height: new Length(50), background: new Color(0, 1, 0)));
        $blue = $this->box($this->style(float: 'left', width: new Length(200), height: new Length(50), background: new Color(0, 0, 1)));
        $result = $this->layout([$red, $green, $blue, $this->box($this->style(clear: 'both'), text: 'after')]);
        self::assertSame([10.0, 10.0, 100.0, 50.0], $this->rectangle($result, 'red'));
        self::assertSame([290.0, 10.0, 100.0, 50.0], $this->rectangle($result, 'green'));
        self::assertSame([10.0, 60.0, 200.0, 50.0], $this->rectangle($result, 'blue'));
        self::assertGreaterThan(110, $this->texts($result)[0]->y);
    }

    public function testNormalWrappersShareFloatsWithTheirFollowingSiblings(): void
    {
        $first = $this->box($this->style(float: 'left', width: new Length(100), height: new Length(50), background: new Color(1, 0, 0)));
        $wrapper = $this->box($this->style(), [$first]);
        $second = $this->box($this->style(float: 'left', width: new Length(100), height: new Length(20), background: new Color(0, 1, 0)));
        $result = $this->layout([$wrapper, $second, $this->box($this->style(clear: 'left'), text: 'after')]);
        self::assertSame([10.0, 10.0, 100.0, 50.0], $this->rectangle($result, 'red'));
        self::assertSame([110.0, 10.0, 100.0, 20.0], $this->rectangle($result, 'green'));
        self::assertGreaterThan(60, $this->texts($result)[0]->y);
        self::assertLessThan(76, $this->texts($result)[0]->y);
    }

    public function testNestedFloatsHaveAnIndependentFormattingContext(): void
    {
        $inside = $this->box($this->style(float: 'right', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $outer = $this->box($this->style(float: 'left', width: new Length(200), height: new Length(120)), [$inside]);
        $sibling = $this->box($this->style(float: 'left', width: new Length(100), height: new Length(60), background: new Color(0, 1, 0)));
        $result = $this->layout([$outer, $sibling]);
        self::assertSame([130.0, 10.0, 80.0, 50.0], $this->rectangle($result, 'red'));
        self::assertSame([210.0, 10.0, 100.0, 60.0], $this->rectangle($result, 'green'));
    }

    public function testAutoHeightFloatContainsItsNestedFloats(): void
    {
        $inside = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $outer = $this->box($this->style(float: 'left', width: new Length(200), background: new Color(0, 1, 0)), [$inside]);
        $result = $this->layout([$outer, $this->box($this->style(clear: 'both'), text: 'after')]);
        self::assertSame([10.0, 10.0, 200.0, 50.0], $this->rectangle($result, 'green'));
        self::assertSame([10.0, 10.0, 80.0, 50.0], $this->rectangle($result, 'red'));
        $rectangles = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle));
        self::assertSame(1.0, $rectangles[0]->color->green);
        self::assertSame(1.0, $rectangles[1]->color->red);
        self::assertGreaterThan(60, $this->texts($result)[0]->y);
    }

    public function testClearancePlacesTheBorderEdgeBelowFloatsWithoutAddingTheTopMarginTwice(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50)));
        $cleared = $this->box($this->style(clear: 'both', margin: new Edges(top: 20), background: new Color(1, 0, 0)), text: 'after');
        $result = $this->layout([$float, $cleared]);
        self::assertSame([10.0, 60.0, 380.0, 16.0], $this->rectangle($result, 'red'));
    }

    public function testLeadingFloatDoesNotHideUnsupportedParentChildMarginCollapse(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $paragraph = $this->box($this->style(margin: new Edges(top: 20)), text: 'content', tag: 'p');
        $wrapper = $this->box($this->style(), [$float, $paragraph]);
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('Parent-child margin collapse');
        $this->layout([$wrapper]);
    }

    public function testFloatInsideAnEmptyWrapperDoesNotHideAdjoiningChildMargin(): void
    {
        $float = $this->box($this->style(float: 'right', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $emptyWrapper = $this->box($this->style(), [$float]);
        $paragraph = $this->box($this->style(margin: new Edges(top: 20)), text: 'content', tag: 'p');
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('Parent-child margin collapse');
        $this->layout([$this->box($this->style(), [$emptyWrapper, $paragraph])]);
    }

    public function testParentPaddingSeparatesChildMarginAfterALeadingFloat(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $paragraph = $this->box($this->style(margin: new Edges(top: 20)), text: 'content', tag: 'p');
        $result = $this->layout([$this->box($this->style(padding: new Edges(top: 1)), [$float, $paragraph])]);
        self::assertSame([10.0, 11.0, 80.0, 50.0], $this->rectangle($result, 'red'));
        self::assertSame(['content'], array_column($this->texts($result), 'text'));
        self::assertGreaterThan(31, $this->texts($result)[0]->y);
    }

    public function testAnAncestorBackgroundPaintsBeforeItsOwnDescendantFloats(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $parent = $this->box($this->style(background: new Color(0, 0, 1)), [$float, $this->box($this->style(clear: 'both'), text: 'after')]);
        $result = $this->layout([$parent]);
        $rectangles = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle));
        self::assertSame(1.0, $rectangles[0]->color->blue);
        self::assertSame(1.0, $rectangles[1]->color->red);
    }

    public function testANormalBackgroundCannotSilentlyCoverAnEarlierFloat(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $paragraph = $this->box($this->style(background: new Color(0, 0, 1)), text: 'alongside');
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('background or border beneath earlier floats');
        $this->layout([$float, $paragraph]);
    }

    public function testANormalBorderCannotSilentlyPaintOverAnEarlierFloat(): void
    {
        $float = $this->box($this->style(float: 'right', width: new Length(80), height: new Length(50)));
        $paragraph = $this->box($this->style(borderWidth: 2), text: 'alongside');
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('background or border beneath earlier floats');
        $this->layout([$float, $paragraph]);
    }

    public function testInlineContentBeforeAFloatIsRejectedRatherThanMovingTheFloatDown(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50)));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('after inline content');
        $this->layout([new Node('leading-text', '#text', text: 'before', style: $this->style(display: 'inline')), $float]);
    }

    public function testCollapsedWhitespaceBeforeFloatsDoesNotCreateAnInlineRun(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(80), height: new Length(50), background: new Color(1, 0, 0)));
        $result = $this->layout([new Node('leading-whitespace', '#text', text: " \n\t ", style: $this->style(display: 'inline')), $float]);
        self::assertSame([10.0, 10.0, 80.0, 50.0], $this->rectangle($result, 'red'));
    }

    public function testExplicitHeightKeepsItsBackgroundAndAllowsVisibleTextOverflow(): void
    {
        $float = $this->box($this->style(float: 'left', width: new Length(120), height: new Length(20), whiteSpace: 'pre', background: new Color(1, 0, 0)), text: "one\ntwo\nthree");
        $result = $this->layout([$float, $this->box($this->style(clear: 'both'), text: 'after')]);
        self::assertSame([10.0, 10.0, 120.0, 20.0], $this->rectangle($result, 'red'));
        $texts = $this->texts($result);
        self::assertSame(['one', 'two', 'three', 'after'], array_column($texts, 'text'));
        self::assertLessThan($texts[2]->y, $texts[3]->y);
    }

    public function testDisplayBlockSuppressesListMarkersOnAFloatedListItem(): void
    {
        $item = $this->box($this->style(float: 'left', width: new Length(120), height: new Length(20)), text: 'item', tag: 'li');
        $result = $this->layout([$this->box($this->style(), [$item], tag: 'ul')]);
        self::assertSame(['item'], array_column($this->texts($result), 'text'));
        self::assertNotContains('Lbl', array_column($result->semantics, 'role'));
    }

    public function testPercentFloatWidthUsesTheContainingContentWidth(): void
    {
        $item = $this->box($this->style(float: 'left', width: new Length(50, true), height: new Length(20), padding: new Edges(5, 10, 5, 10), borderWidth: 2, background: new Color(1, 0, 0)));
        self::assertSame([10.0, 10.0, 214.0, 34.0], $this->rectangle($this->layout([$item]), 'red'));
    }

    public function testAutoWidthIsRejectedExplicitly(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('explicit width');
        $this->layout([$this->box($this->style(float: 'left', height: new Length(20)))]);
    }

    public function testFloatsInHiddenAncestorsDoNotChangeVisibleNormalFlow(): void
    {
        $hidden = $this->box($this->style(display: 'none'), [$this->box($this->style(float: 'left'), text: 'hidden')]);
        $visible = $this->box($this->style(margin: new Edges(top: 20)), text: 'visible');
        $result = $this->layout([$hidden, $visible]);
        self::assertSame(['visible'], array_column($this->texts($result), 'text'));
        self::assertCount(1, $result->pages);
    }

    public function testFloatPaginationCannotSilentlyClipAnOversizedContext(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('float fragmentation');
        $this->layout([$this->box($this->style(float: 'left', width: new Length(100), height: new Length(600)))]);
    }

    public function testVisibleOverflowAlsoCountsAgainstPageFit(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('float fragmentation');
        $this->layout([$this->box($this->style(float: 'left', width: new Length(100), height: new Length(20), whiteSpace: 'pre'), text: implode("\n", range(1, 40)))]);
    }

    private function style(mixed ...$values): ComputedStyle
    {
        return new ComputedStyle(...array_replace(['display' => 'block', 'fontSize' => 10, 'lineHeight' => 16], $values));
    }

    /** @param list<Node> $children */
    private function box(ComputedStyle $style, array $children = [], string $text = '', string $tag = 'div'): Node
    {
        if ($text !== '') {
            $children[] = new Node('text-' . ++$this->sequence, '#text', text: $text, style: $style);
        }

        return new Node('box-' . ++$this->sequence, $tag, children: $children, style: $style);
    }

    /** @param list<Node> $children */
    private function layout(array $children): PagedDocument
    {
        return (new LayoutEngine())->layout(new HtmlDocument($this->box($this->style(), $children, tag: 'body')), new ConversionOptions(new FontSet(self::$font), new PageSettings(400, 600, new Edges(10, 10, 10, 10))), new Diagnostics());
    }

    /** @return array{float,float,float,float} */
    private function rectangle(PagedDocument $document, string $component): array
    {
        $rectangles = array_values(array_filter($document->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle && $command->color?->$component === 1.0));
        $maximumWidth = max(array_column($rectangles, 'width'));
        $rectangles = array_values(array_filter($rectangles, static fn(Rectangle $rectangle): bool => $rectangle->width === $maximumWidth));
        self::assertCount(1, $rectangles);
        $box = $rectangles[0];

        return [$box->x, $box->y, $box->width, $box->height];
    }

    /** @return list<Text> */
    private function texts(PagedDocument $document): array
    {
        return array_values(array_filter($document->pages[0]->commands, static fn($command): bool => $command instanceof Text));
    }
}
