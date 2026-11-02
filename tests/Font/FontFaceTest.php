<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontLimits;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Font\FontStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FontFaceTest extends TestCase
{
    private function bytes(): string
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/Fixtures/fonts/NotoSans-Regular.ttf');
        self::assertIsString($bytes);

        return $bytes;
    }

    public function testMeasuresPositionedGlyphsAndPreservesSourceUnicode(): void
    {
        $font = new FontFace($this->bytes());
        self::assertEqualsWithDelta(16.786, $font->measure('AV', 14), 0.00001);
        self::assertLessThan($font->measure('A', 14) + $font->measure('V', 14), $font->measure('AV', 14));
        self::assertEqualsWithDelta(2 * $font->measure('AV', 14), $font->measure('AV', 28), 0.00001);
        $run = $font->shape("été fi A\u{0301}", 14);
        self::assertSame("été fi A\u{0301}", $run->text->source->utf8);
        self::assertSame($font->program->font, $run->text->font);
        self::assertSame($font, $run->font);
        self::assertGreaterThan(0, $run->metrics->ascent);
        self::assertGreaterThan(0, $run->metrics->descent);
        self::assertGreaterThan($run->size, $run->metrics->height());
        self::assertSame(0.0, $font->measure('', 14));
    }

    public function testFontVariantsAreExplicit(): void
    {
        $face = new FontFace($this->bytes());
        $set = new FontSet($face);
        self::assertSame($face, $set->face(FontStyle::Regular));
        $this->expectException(UnsupportedFeature::class);
        $set->face(FontStyle::Bold);
    }

    public function testRejectsUnimplementedScriptShaping(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new FontFace($this->bytes()))->shape('العربية', 12);
    }

    public function testRejectsBidirectionalControlsEvenWithLatinLetters(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new FontFace($this->bytes()))->shape("a\u{202E}b", 12);
    }

    public function testRejectsInvalidUnicode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FontFace($this->bytes()))->shape("\xFF", 12);
    }

    public function testEnforcesFontByteBudgetBeforeParsing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontFace($this->bytes(), new FontLimits(maxBytes: 100));
    }

    public function testEnforcesTextByteBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FontFace($this->bytes(), new FontLimits(maxTextBytes: 4)))->shape('hello', 12);
    }

    #[DataProvider('invalidSizes')]
    public function testRejectsInvalidSize(float $size): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FontFace($this->bytes()))->metrics($size);
    }

    public static function invalidSizes(): iterable
    {
        yield [0.0];
        yield [-1.0];
        yield [INF];
        yield [NAN];
    }
}
