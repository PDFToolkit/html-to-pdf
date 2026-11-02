<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Layout\FloatContext;
use PDF\HtmlToPdf\Layout\InlineBlockSplitter;
use PDF\HtmlToPdf\Layout\InlineFormatter;
use PDF\HtmlToPdf\Layout\InlinePiece;
use PDF\HtmlToPdf\Layout\LineExclusions;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

final class InlineFormattingTest extends TestCase
{
    private static FontFace $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    private function formatter(): InlineFormatter
    {
        return new InlineFormatter(new ConversionOptions(new FontSet(self::$font)));
    }

    public function testNegativeLeadingDoesNotExpandTheSpecifiedLineHeight(): void
    {
        $style = new ComputedStyle(fontSize: 12, lineHeight: 7);
        $lines = $this->formatter()->format([new InlinePiece('alpha', $style, 'text'), new InlinePiece('', $style, 'text', lineBreak: true), new InlinePiece('beta', $style, 'text')], 0, 100, $style, 'paragraph');
        self::assertCount(2, $lines);
        self::assertEqualsWithDelta(7, $lines[0]->height, 0.00001);
        self::assertEqualsWithDelta(14, array_sum(array_column($lines, 'height')), 0.00001);
        $metrics = self::$font->metrics(12);
        self::assertEqualsWithDelta($metrics->ascent + (7 - $metrics->ascent - $metrics->descent) / 2, $lines[0]->commands[0]->y, 0.00001);
    }

    public function testWideWordDescendsBelowFloatAndPreservesVerticalProgression(): void
    {
        $style = new ComputedStyle(fontSize: 10, lineHeight: 12);
        $floats = new FloatContext(1000);
        $floats->place('left', 85, 30, 0, 0, 100);
        $lines = $this->formatter()->format([new InlinePiece('unbreakable', $style, 'text')], 0, 100, $style, 'p', new LineExclusions($floats, 0, 100));
        self::assertCount(1, $lines);
        self::assertEqualsWithDelta(42, $lines[0]->height, 0.00001);
        self::assertSame(0.0, $lines[0]->commands[0]->x);
        self::assertGreaterThan(30, $lines[0]->commands[0]->y);
    }

    public function testNarrowedLinesRecoverTheFullWidthAfterFloat(): void
    {
        $style = new ComputedStyle(fontSize: 10, lineHeight: 12);
        $floats = new FloatContext(1000);
        $floats->place('left', 50, 24, 0, 0, 100);
        $lines = $this->formatter()->format([new InlinePiece(str_repeat('alpha ', 12), $style, 'text')], 0, 100, $style, 'p', new LineExclusions($floats, 0, 100));
        self::assertSame(50.0, $lines[0]->commands[0]->x);
        self::assertSame(50.0, $lines[1]->commands[0]->x);
        self::assertSame(0.0, $lines[2]->commands[0]->x);
        self::assertGreaterThan(strlen($lines[0]->commands[0]->text), strlen($lines[2]->commands[0]->text));
    }

    public function testStaticRadioParticipatesInBaselineAndDoesNotSwallowAdjacentText(): void
    {
        $style = new ComputedStyle(fontSize: 10, lineHeight: 12);
        $radio = new RadioButton(0, 0, 9.75, 9.75, true, true, 'control');
        $lines = $this->formatter()->format([new InlinePiece('a', $style, 'text'), new InlinePiece('', $style, 'control', radio: $radio), new InlinePiece('b', $style, 'text')], 0, 100, $style, 'p');
        self::assertCount(3, $lines[0]->commands);
        [$before, $control, $after] = $lines[0]->commands;
        self::assertInstanceOf(RadioButton::class, $control);
        self::assertInstanceOf(Text::class, $after);
        self::assertSame('b', $after->text);
        self::assertSame($before->y, $control->y + $control->height);
        self::assertSame($control->x + 9.75, $after->x);
        self::assertTrue($control->checked && $control->disabled);
    }

    public function testInlineAncestorSplitsAroundBlocksInSourceOrder(): void
    {
        $style = new ComputedStyle();
        $before = new Node('before', '#text', text: 'before', style: $style);
        $after = new Node('after', '#text', text: 'after', style: $style);
        $block = new Node('block', 'p', style: new ComputedStyle(display: 'block'));
        $span = new Node('span', 'span', children: [$before, $block, $after], style: $style);
        $parts = (new InlineBlockSplitter())->split([$span]);
        self::assertCount(3, $parts);
        self::assertSame([$before], $parts[0]->children);
        self::assertSame($block, $parts[1]);
        self::assertSame([$after], $parts[2]->children);
        self::assertNotSame($parts[0]->id, $parts[2]->id);
    }

    public function testLinkContextCannotBeLostWhenSplittingAnInlineAncestor(): void
    {
        $block = new Node('block', 'div', style: new ComputedStyle(display: 'block'));
        $link = new Node('link', 'a', ['href' => 'https://example.test/'], [$block]);
        $this->expectException(UnsupportedFeature::class);
        (new InlineBlockSplitter())->split([$link]);
    }

    public function testLanguageScopeCannotBeLostWhenSplittingAnInlineAncestor(): void
    {
        $block = new Node('block', 'div', style: new ComputedStyle(display: 'block'));
        $span = new Node('span', 'span', ['lang' => 'fr'], [$block]);
        $this->expectException(UnsupportedFeature::class);
        (new InlineBlockSplitter())->split([$span]);
    }

    public function testDecoratedSplitInlineIsExplicitlyRejected(): void
    {
        $block = new Node('block', 'p', style: new ComputedStyle(display: 'block'));
        $span = new Node('span', 'span', children: [$block], style: new ComputedStyle(background: new Color(1, 0, 0)));
        $this->expectException(UnsupportedFeature::class);
        (new InlineBlockSplitter())->split([$span]);
    }
}
