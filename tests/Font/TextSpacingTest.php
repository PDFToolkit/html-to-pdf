<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\Fonts\TrueType\UnicodeText;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontLimits;
use PDF\HtmlToPdf\Font\GlyphSpacing;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDFToolkit\OpenType\Positioning\GlyphPosition;
use PDFToolkit\OpenType\Positioning\PositionedGlyphSequence;
use PDFToolkit\OpenType\Substitution\GlyphSequence;
use PDFToolkit\OpenType\Value\GlyphId;
use PHPUnit\Framework\TestCase;

final class TextSpacingTest extends TestCase
{
    private static FontFace $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(dirname(__DIR__) . '/Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    public function testNormalAndExplicitZeroPreserveLegacyShaping(): void
    {
        $normal = self::$font->shape("fi A\u{0301}", 12);
        $zero = self::$font->shape("fi A\u{0301}", 12, new TextSpacing(0, 0));
        self::assertSame($normal->width, $zero->width);
        self::assertEquals($normal->text, $zero->text);
        self::assertSame(0.0, self::$font->measure('', 12, new TextSpacing(2, 3)));
    }

    public function testLetterSpacingUsesAbsolutePointsAndKeepsKerning(): void
    {
        $spacing = new TextSpacing(letterSpacing: 2);
        foreach ([12.0, 24.0] as $size) {
            $normal = self::$font->shape('AV', $size);
            $spaced = self::$font->shape('AV', $size, $spacing);
            self::assertEqualsWithDelta($normal->width + 4, $spaced->width, 0.000001);
            self::assertSame($spaced->width, self::$font->measure('AV', $size, $spacing));
            self::assertSame($spacing, $spaced->spacing);
        }
    }

    public function testNonzeroLetterSpacingDisablesOptionalLigaturesWithoutLosingUnicode(): void
    {
        $normal = self::$font->shape('fi', 12);
        $spaced = self::$font->shape('fi', 12, new TextSpacing(letterSpacing: 1));
        self::assertCount(1, $normal->text->glyphs);
        self::assertCount(2, $spaced->text->glyphs);
        self::assertSame('fi', implode('', array_map(static fn($glyph): string => $glyph->unicode->utf8(), $spaced->text->glyphs)));
        self::assertEqualsWithDelta(self::$font->measure('f', 12) + self::$font->measure('i', 12) + 2, $spaced->width, 0.000001);
    }

    public function testWordSpacingPreservesLigaturesAndCountsOrdinaryAndNoBreakSpaces(): void
    {
        $text = "fi a\u{00a0}b\u{2003}c";
        $normal = self::$font->shape($text, 12);
        $spaced = self::$font->shape($text, 12, new TextSpacing(wordSpacing: 3));
        self::assertEqualsWithDelta($normal->width + 6, $spaced->width, 0.000001);
        self::assertEquals($normal->text->glyphs, $spaced->text->glyphs);
        self::assertSame($text, implode('', array_map(static fn($glyph): string => $glyph->unicode->utf8(), $spaced->text->glyphs)));
    }

    public function testCombiningMarkStaysAttachedAndReceivesNoIndependentLetterSpace(): void
    {
        $text = "A\u{0301}B";
        $normal = self::$font->shape($text, 12);
        $spaced = self::$font->shape($text, 12, new TextSpacing(letterSpacing: 2));
        self::assertCount(3, $normal->text->glyphs);
        self::assertEqualsWithDelta($normal->width + 4, $spaced->width, 0.000001);
        self::assertEquals($normal->text->layout->positions[0], $spaced->text->layout->positions[0]);
        self::assertSame($normal->text->layout->positions[1]->xOffset, $spaced->text->layout->positions[1]->xOffset);
        self::assertSame($normal->text->layout->positions[1]->yOffset, $spaced->text->layout->positions[1]->yOffset);
        self::assertEquals($normal->text->glyphs, $spaced->text->glyphs);
    }

    public function testNegativeSpacingCompactsWithoutChangingSourceText(): void
    {
        $normal = self::$font->measure('A B', 12);
        $compact = self::$font->shape('A B', 12, new TextSpacing(-0.5, -1));
        self::assertEqualsWithDelta($normal - 2.5, $compact->width, 0.000001);
        self::assertSame('A B', $compact->text->source->utf8);
    }

    public function testNegativeTotalAdvanceIsExplicitlyUnsupported(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('non-negative run advance');
        self::$font->shape('A', 12, new TextSpacing(letterSpacing: -100));
    }

    public function testSpacingStillEnforcesTheFontTextBudget(): void
    {
        $font = new FontFace(file_get_contents(dirname(__DIR__) . '/Fixtures/fonts/NotoSans-Regular.ttf'), new FontLimits(maxTextBytes: 2));
        $this->expectException(\InvalidArgumentException::class);
        $font->shape('ABC', 12, new TextSpacing(letterSpacing: 1));
    }

    public function testAmbiguousRequiredLigatureCannotReceiveInventedInternalSpacing(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1)], componentClusters: [[0, 1]]), [new GlyphPosition(600)]);
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('shared by different graphemes');
        (new GlyphSpacing())->apply(new UnicodeText('AB'), $layout, new TextSpacing(letterSpacing: 1), 100);
    }

    public function testReorderedGraphemesAreRejectedByTheHorizontalProfile(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1), new GlyphId(2)], componentClusters: [[1], [0]]), [new GlyphPosition(600), new GlyphPosition(600)]);
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('ordered grapheme positions');
        (new GlyphSpacing())->apply(new UnicodeText('AB'), $layout, new TextSpacing(letterSpacing: 1), 100);
    }

    public function testZeroAdvanceSeparatorDoesNotAcquireWordSpacing(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1)]), [new GlyphPosition()]);
        $spaced = (new GlyphSpacing())->apply(new UnicodeText(' '), $layout, new TextSpacing(wordSpacing: 2), 100);
        self::assertEquals($layout, $spaced);
    }

    public function testOneSourceGraphemeExpandedToSeveralGlyphsGetsOneSpace(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1), new GlyphId(2)], componentClusters: [[0], [0]]), [new GlyphPosition(300), new GlyphPosition(200)]);
        $spaced = (new GlyphSpacing())->apply(new UnicodeText('A'), $layout, new TextSpacing(letterSpacing: 2), 100);
        self::assertSame(300.0, $spaced->positions[0]->xAdvance);
        self::assertSame(400.0, $spaced->positions[1]->xAdvance);
        self::assertSame($layout->sequence, $spaced->sequence);
    }

    public function testWordSeparatorInsideASharedGlyphIsExplicitlyRejected(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1)], componentClusters: [[0, 1]]), [new GlyphPosition(600)]);
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('unambiguous');
        (new GlyphSpacing())->apply(new UnicodeText('A '), $layout, new TextSpacing(wordSpacing: 1), 100);
    }

    public function testDeletedSourceGraphemeCannotSilentlyLoseItsSpacing(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1)]), [new GlyphPosition(600)]);
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('without a glyph');
        (new GlyphSpacing())->apply(new UnicodeText('AB'), $layout, new TextSpacing(letterSpacing: 1), 100);
    }

    public function testReorderedWordSeparatorCannotReceiveSpacingAtTheWrongPosition(): void
    {
        $layout = new PositionedGlyphSequence(new GlyphSequence([new GlyphId(1), new GlyphId(2)], componentClusters: [[1], [0]]), [new GlyphPosition(200), new GlyphPosition(600)]);
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('ordered grapheme positions');
        (new GlyphSpacing())->apply(new UnicodeText('A '), $layout, new TextSpacing(wordSpacing: 1), 100);
    }
}
