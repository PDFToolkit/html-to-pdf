<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\Fonts\OpenType\GlyphText;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FaceChain;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontLimits;
use PDF\HtmlToPdf\Font\SmallCapitals;
use PDF\HtmlToPdf\Font\TextItemizer;
use PDF\HtmlToPdf\Font\TextSegment;
use PDF\HtmlToPdf\Style\TextSpacing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CSS1 5.2.4: small capitals synthesized from the uppercase glyphs of a normal face. */
final class SmallCapitalsTest extends TestCase
{
    private static ?FontFace $lato = null;
    private static ?FontFace $noto = null;

    public static function setUpBeforeClass(): void
    {
        self::$lato = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'));
        self::$noto = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    /** Prepared layout tables are large: release them with the class. */
    public static function tearDownAfterClass(): void
    {
        self::$lato = self::$noto = null;
    }

    /** @return iterable<string,array{string,bool}> */
    public static function clusters(): iterable
    {
        yield 'lowercase letter' => ['a', true];
        yield 'accented lowercase' => ['é', true];
        yield 'decomposed accent' => ["e\u{0301}", true];
        yield 'sharp s' => ['ß', true];
        yield 'Greek' => ['λ', true];
        yield 'Cyrillic' => ['ж', true];
        yield 'uppercase letter' => ['A', false];
        yield 'digit' => ['7', false];
        yield 'space' => [' ', false];
        yield 'no-break space' => ["\u{00A0}", false];
        yield 'punctuation' => ['!', false];
        yield 'ordinal indicator without case' => ['ª', false];
    }

    #[DataProvider('clusters')]
    public function testOnlyLettersThatUppercasingChangesAreTransformed(string $cluster, bool $transformed): void
    {
        self::assertSame($transformed, (new SmallCapitals())->transforms($cluster));
    }

    public function testUppercaseMappingIsTheFullUnicodeMapping(): void
    {
        $capitals = new SmallCapitals();
        self::assertSame([0x41], $capitals->uppercase(0x61));
        self::assertSame([0x41], $capitals->uppercase(0x41));
        self::assertSame([0x31], $capitals->uppercase(0x31));
        self::assertSame([0x53, 0x53], $capitals->uppercase(0xDF), 'sharp s becomes SS, as in both browser witnesses');
        self::assertSame([0x2BC, 0x4E], $capitals->uppercase(0x149));
        self::assertSame([0x301], $capitals->uppercase(0x301));
        self::assertSame(0.8, SmallCapitals::SCALE);
    }

    public function testCapitalsShapeTheUppercaseGlyphsAndKeepTheSourceText(): void
    {
        $run = self::$lato->shape('hxn', 8, capitals: true);
        $upper = self::$lato->shape('HXN', 8);
        self::assertSame('hxn', $run->text->source->utf8);
        self::assertSame($upper->width, $run->width);
        self::assertEquals($upper->text->layout->sequence->glyphs, $run->text->layout->sequence->glyphs);
        self::assertEquals($upper->text->layout->positions, $run->text->layout->positions);
        self::assertSame(['0068', '0078', '006e'], array_map(static fn(GlyphText $glyph): string => bin2hex($glyph->unicode->value->value), $run->text->glyphs), 'the PDF Unicode map keeps the letters as written');
    }

    public function testOneSourceLetterMayDrawSeveralCapitals(): void
    {
        $run = self::$lato->shape('aß', 10, capitals: true);
        self::assertSame(self::$lato->measure('ASS', 10), $run->width);
        self::assertCount(3, $run->text->glyphs);
        self::assertSame(['0061', '00df', ''], array_map(static fn(GlyphText $glyph): string => bin2hex($glyph->unicode->value->value), $run->text->glyphs));
        // Spacing belongs to source graphemes: one letter space after "a" and one after "ß".
        self::assertEqualsWithDelta($run->width + 2 * 3, self::$lato->measure('aß', 10, new TextSpacing(letterSpacing: 3), true), 1e-9);
    }

    public function testCapitalsDoNotChangeTextWithoutLowercase(): void
    {
        self::assertEquals(self::$lato->shape('AB 12', 10)->text->layout, self::$lato->shape('AB 12', 10, capitals: true)->text->layout);
    }

    public function testTheGlyphBudgetCountsTheCapitalsReallyDrawn(): void
    {
        $face = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'), new FontLimits(maxGlyphs: 2));
        self::assertGreaterThan(0, $face->measure('aß', 10));
        // Two characters, three glyphs: A, S, S.
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('glyph budget');
        $face->shape('aß', 10, capitals: true);
    }

    public function testAMissingUppercaseGlyphIsNamed(): void
    {
        self::assertTrue(self::$lato->hasGlyph(0x251));
        self::assertFalse(self::$lato->hasGlyph(0x2C6D));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('No glyph for U+2C6D');
        self::$lato->shape("\u{0251}", 10, capitals: true);
    }

    public function testItemizerSeparatesLowercaseRunsFromEverythingElse(): void
    {
        $face = new FamilyFace(self::$lato);
        $segments = (new TextItemizer())->itemize('Hello World 42, été!', new FaceChain([$face], true, false), true);
        self::assertSame(
            [['H', false], ['ello', true], [' W', false], ['orld', true], [' 42, ', false], ['été', true], ['!', false]],
            array_map(static fn(TextSegment $segment): array => [$segment->text, $segment->capitals], $segments),
        );
        self::assertSame('Hello World 42, été!', implode('', array_map(static fn(TextSegment $segment): string => $segment->text, $segments)));
    }

    public function testNormalVariantIsNotItemizedForCase(): void
    {
        $face = new FamilyFace(self::$lato);
        self::assertEquals([new TextSegment($face, 'Hello')], (new TextItemizer())->itemize('Hello', new FaceChain([$face], true, false)));
    }

    public function testAFaceLabeledSmallCapsDrawsTheTextAsWritten(): void
    {
        $labeled = new FamilyFace(self::$lato, 400, smallCaps: true);
        $segments = (new TextItemizer())->itemize('Hello World', new FaceChain([$labeled], true, false), true);
        self::assertEquals([new TextSegment($labeled, 'Hello World')], $segments);
    }

    public function testTheGlyphRequiredForFallbackIsTheOneDrawn(): void
    {
        $lato = new FamilyFace(self::$lato);
        $noto = new FamilyFace(self::$noto);
        // U+0377 and its capital U+0376 exist in Noto Sans only.
        self::assertTrue(self::$noto->hasGlyph(0x376) && !self::$lato->hasGlyph(0x376));
        $segments = (new TextItemizer())->itemize("a\u{0377}b", new FaceChain([$lato, $noto], true, false), true);
        self::assertSame([[$lato, 'a', true], [$noto, "\u{0377}", true], [$lato, 'b', true]], array_map(static fn(TextSegment $segment): array => [$segment->face, $segment->text, $segment->capitals], $segments));
        // Lato has the lowercase U+0251 but not its capital U+2C6D; Noto Sans, tried next, has the capital.
        self::assertTrue(self::$noto->hasGlyph(0x2C6D));
        $segments = (new TextItemizer())->itemize("a\u{0251}", new FaceChain([$lato, $noto], true, false), true);
        self::assertSame([[$lato, 'a', true], [$noto, "\u{0251}", true]], array_map(static fn(TextSegment $segment): array => [$segment->face, $segment->text, $segment->capitals], $segments));
    }

    public function testALetterWithoutAnyUppercaseGlyphIsDrawnAsWritten(): void
    {
        $lato = new FamilyFace(self::$lato);
        $segments = (new TextItemizer())->itemize("a\u{0251}b", new FaceChain([$lato], true, false), true);
        self::assertSame(
            [['a', true, false], ["\u{0251}", false, true], ['b', true, false]],
            array_map(static fn(TextSegment $segment): array => [$segment->text, $segment->capitals, $segment->unchanged], $segments),
        );
    }
}
