<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\InlineFormatter;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontFamilyName;
use PHPUnit\Framework\TestCase;

/** CSS1 5.2.1 step 4 in the laid-out page: per-character fallback with shared advances. */
final class FontFallbackLayoutTest extends TestCase
{
    private static ?FontFace $noto = null;
    private static ?FontFace $lato = null;

    public static function setUpBeforeClass(): void
    {
        self::$noto = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
        self::$lato = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'));
    }

    /** Prepared layout tables are large: release them with the class. */
    public static function tearDownAfterClass(): void
    {
        self::$noto = self::$lato = null;
    }

    public function testMissingCharactersUseTheNextFamilyAndEveryRunStartsWhereThePreviousEnds(): void
    {
        [$document, $diagnostics] = $this->layout('<p style="font-family:Lato, Noto">Set ' . "\u{211D}" . ' and ' . "\u{0377}" . '.</p>');
        $texts = $this->texts($document);
        self::assertSame(['Set ', "\u{211D}", ' and ', "\u{0377}", '.'], array_map(static fn(Text $text): string => $text->text, $texts));
        self::assertSame([self::$lato, self::$noto, self::$lato, self::$noto, self::$lato], array_map(static fn(Text $text): FontFace => $text->font, $texts));
        $x = 20.0;
        foreach ($texts as $text) {
            self::assertEqualsWithDelta($x, $text->x, 1e-9);
            self::assertSame($texts[0]->y, $text->y);
            self::assertSame($texts[0]->semanticId, $text->semanticId);
            $x += $text->font->measure($text->text, $text->size, $text->spacing);
        }
        self::assertSame([], $diagnostics->all(), 'listing a second family is the author\'s own fallback');
    }

    public function testTextTheFirstFamilyCoversKeepsItsSingleCommand(): void
    {
        [$document] = $this->layout('<p style="font-family:Lato, Noto">One plain run of text</p>');
        $texts = $this->texts($document);
        self::assertCount(1, $texts);
        self::assertSame('One plain run of text', $texts[0]->text);
        self::assertSame(self::$lato, $texts[0]->font);
    }

    public function testTheDefaultFamilyIsTheLastResortForAGlyph(): void
    {
        // Step 5: after the listed families, the default family is tried.
        [$document, $diagnostics] = $this->layout('<p style="font-family:Lato">a' . "\u{211D}" . 'b</p>');
        self::assertSame([self::$lato, self::$noto, self::$lato], array_map(static fn(Text $text): FontFace => $text->font, $this->texts($document)));
        self::assertSame([], $diagnostics->all());
    }

    public function testLineBreakingUsesTheSummedAdvances(): void
    {
        $word = "\u{211D}a\u{0377}b";
        $width = self::$noto->measure("\u{211D}", 10) + self::$lato->measure('a', 10) + self::$noto->measure("\u{0377}", 10) + self::$lato->measure('b', 10);
        $space = self::$lato->measure(' ', 10);
        // Two words fit exactly; a third must wrap.
        [$document] = $this->layout('<p style="font-family:Lato, Noto;width:' . (2 * $width + $space + 0.01) . 'pt">' . $word . ' ' . $word . ' ' . $word . '</p>');
        $lines = [];
        foreach ($this->texts($document) as $text) {
            $lines[(string) $text->y] = ($lines[(string) $text->y] ?? '') . $text->text;
        }
        self::assertSame([$word . ' ' . $word, $word], array_values($lines));

        [$narrow] = $this->layout('<p style="font-family:Lato, Noto;width:' . (2 * $width + $space - 0.01) . 'pt">' . $word . ' ' . $word . '</p>');
        self::assertCount(2, array_unique(array_map(static fn(Text $text): float => $text->y, $this->texts($narrow))));
    }

    public function testLinksDecorationsAndBackgroundsSpanAllRuns(): void
    {
        [$document] = $this->layout('<p style="font-family:Lato, Noto"><a href="https://example.test/" style="text-decoration:underline;background-color:#ffee00">a' . "\u{211D}" . 'b</a></p>');
        $texts = $this->texts($document);
        self::assertCount(3, $texts);
        $total = 0.0;
        foreach ($texts as $text) {
            self::assertSame('https://example.test/', $text->uri);
            self::assertSame($texts[0]->semanticId, $text->semanticId);
            $total += $text->font->measure($text->text, $text->size, $text->spacing);
        }
        $rectangles = array_values(array_filter($document->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle));
        self::assertCount(2, $rectangles, 'one background and one underline for the whole inline box');
        foreach ($rectangles as $rectangle) {
            self::assertEqualsWithDelta($texts[0]->x, $rectangle->x, 1e-9);
            self::assertEqualsWithDelta($total, $rectangle->width, 1e-9);
        }
    }

    public function testSpacingStaysAdditiveAcrossRuns(): void
    {
        [$plain] = $this->layout('<p style="font-family:Lato, Noto">a' . "\u{211D}" . 'b c</p>');
        [$spaced] = $this->layout('<p style="font-family:Lato, Noto;letter-spacing:2pt;word-spacing:3pt">a' . "\u{211D}" . 'b c</p>');
        $end = static function (PagedDocument $document, array $texts): float {
            $last = $texts[array_key_last($texts)];

            return $last->x + $last->font->measure($last->text, $last->size, $last->spacing);
        };
        // Five graphemes, one of them a space: each keeps its trailing letter space, whatever its face.
        self::assertEqualsWithDelta($end($plain, $this->texts($plain)) + 5 * 2 + 3, $end($spaced, $this->texts($spaced)), 1e-9);
    }

    public function testListMarkersFallBackLikeAnyText(): void
    {
        // Noto Sans has no white circle; Lato has it.
        [$document] = $this->layout('<ul style="font-family:Noto, Lato;list-style-type:circle"><li>item</li></ul>');
        $texts = $this->texts($document);
        self::assertSame(["\u{25CB}", 'item'], array_map(static fn(Text $text): string => $text->text, $texts));
        self::assertSame([self::$lato, self::$noto], array_map(static fn(Text $text): FontFace => $text->font, $texts));
        self::assertSame($texts[0]->y, $texts[1]->y);
    }

    public function testACharacterAbsentFromEveryFamilyIsStillRejectedByName(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('No glyph for U+1F600');
        $this->layout('<p style="font-family:Lato, Noto">a' . "\u{1F600}" . '</p>');
    }

    public function testAClusterDividedBetweenFontsIsReportedOnce(): void
    {
        [$document, $diagnostics] = $this->layout('<p style="font-family:Lato, Noto">x' . "\u{2192}\u{0487}" . 'y</p><p style="font-family:Lato, Noto">' . "\u{2192}\u{0487}" . '</p>');
        self::assertSame(["x\u{2192}", "\u{0487}", 'y', "\u{2192}", "\u{0487}"], array_map(static fn(Text $text): string => $text->text, $this->texts($document)));
        self::assertSame(['font.cluster-split'], array_column($diagnostics->all(), 'code'));
    }

    public function testLetterSpacingCannotDivideAClusterBetweenFonts(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('grapheme divided between fonts');
        $this->layout('<p style="font-family:Lato, Noto;letter-spacing:1pt">' . "\u{2192}\u{0487}" . '</p>');
    }

    public function testFallbackWorkCountsAgainstTheShapingBudget(): void
    {
        $options = new ConversionOptions($this->fonts(), limits: new ConversionLimits(maxTextCharacters: 1));
        $style = new ComputedStyle(fontFamily: new FontFamilyList([new FontFamilyName('Lato'), new FontFamilyName('Noto')]));
        // Budget: 256 bytes. Each divided call costs its bytes twice, for the division and for the measurement.
        $divided = "abc\u{211D}d";
        self::assertSame(7, strlen($divided));
        $formatter = new InlineFormatter($options);
        $rounds = 0;
        try {
            for (; $rounds < 100; ++$rounds) {
                $formatter->advance($divided . str_repeat(' ', $rounds), $style);
            }
            self::fail('The shaping budget must stop repeated fallback work.');
        } catch (LimitExceeded) {
        }
        // Texts grow by one byte per round so that no cache answers: 2 x (7 + n) bytes in round n.
        self::assertSame(10, $rounds, 'without charging the division, the same loop lasts 17 rounds');

        // Text that the first face draws as written is charged once, as before font fallback existed.
        $plain = new InlineFormatter($options);
        $rounds = 0;
        try {
            for (; $rounds < 100; ++$rounds) {
                $plain->advance('abcdefgh' . str_repeat(' ', $rounds), $style);
            }
        } catch (LimitExceeded) {
        }
        self::assertSame(16, $rounds);
    }

    public function testPublicHelpersShareOneGeometry(): void
    {
        $formatter = new InlineFormatter(new ConversionOptions($this->fonts()));
        $style = new ComputedStyle(fontSize: 10, fontFamily: new FontFamilyList([new FontFamilyName('Lato'), new FontFamilyName('Noto')]));
        [$commands, $advance] = $formatter->texts("a\u{211D}b", $style, 5, 30, 'semantic', 'https://example.test/');
        self::assertCount(3, $commands);
        self::assertSame($formatter->advance("a\u{211D}b", $style), $advance);
        self::assertSame(5.0, $commands[0]->x);
        self::assertSame(30.0, $commands[2]->y);
        self::assertSame(self::$lato, $formatter->face($style));
        [$single, $width] = $formatter->texts('', $style, 0, 0, null);
        self::assertCount(1, $single);
        self::assertSame(0.0, $width);
    }

    private function fonts(): FontSet
    {
        return new FontSet(self::$noto, catalog: new FontCatalog(['Lato' => new FontSet(self::$lato), 'Noto' => new FontSet(self::$noto)]));
    }

    /** @return array{PagedDocument,Diagnostics} */
    private function layout(string $html): array
    {
        $options = new ConversionOptions($this->fonts(), new PageSettings(400, 600, new Edges(20, 20, 20, 20)));
        $diagnostics = new Diagnostics();
        $document = (new HtmlCompiler())->parse(new HtmlSource('<style>*{margin:0;padding:0}body{font-size:10pt;line-height:12pt}</style>' . $html), $options, $diagnostics);
        self::assertSame([], $diagnostics->all());

        return [(new LayoutEngine())->layout($document, $options, $diagnostics), $diagnostics];
    }

    /** @return list<Text> */
    private function texts(PagedDocument $document): array
    {
        return array_values(array_filter(array_merge(...array_column($document->pages, 'commands')), static fn($command): bool => $command instanceof Text));
    }
}
