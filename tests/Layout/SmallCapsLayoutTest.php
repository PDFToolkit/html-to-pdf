<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Layout\InlineFormatter;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\LayoutPage;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Model\HtmlDocument;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Model\SemanticNode;
use PDF\HtmlToPdf\Rendering\Renderer;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontFamilyName;
use PDF\HtmlToPdf\Style\Length;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PHPUnit\Framework\TestCase;

/** CSS1 5.2.4 in the page: scaled capitals share the baseline, the advances and the source text. */
final class SmallCapsLayoutTest extends TestCase
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

    public function testLowercaseRunsAreCapitalsAtTheSynthesisScale(): void
    {
        $formatter = new InlineFormatter(new ConversionOptions(new FontSet(self::$lato)));
        $style = new ComputedStyle(fontSize: 10, smallCaps: true);
        [$commands, $advance] = $formatter->texts('Hxn 12', $style, 30, 50, 'semantic', 'https://example.test/');
        self::assertSame([['H', 10.0, false], ['xn', 8.0, true], [' 12', 10.0, false]], array_map(static fn(Text $text): array => [$text->text, $text->size, $text->capitals], $commands));
        $x = 30.0;
        foreach ($commands as $command) {
            self::assertEqualsWithDelta($x, $command->x, 1e-9);
            self::assertSame(50.0, $command->y);
            self::assertSame('semantic', $command->semanticId);
            self::assertSame('https://example.test/', $command->uri);
            $x += $command->font->measure($command->text, $command->size, $command->spacing, $command->capitals);
        }
        self::assertEqualsWithDelta($x - 30, $advance, 1e-9);
        self::assertEqualsWithDelta(self::$lato->measure('H', 10) + self::$lato->measure('XN', 8) + self::$lato->measure(' 12', 10), $advance, 1e-9);
        self::assertSame($advance, $formatter->advance('Hxn 12', $style));
        self::assertLessThan($formatter->advance('HXN 12', $style), $advance);
        self::assertSame($formatter->advance('HXN 12', new ComputedStyle(fontSize: 10)), $formatter->advance('HXN 12', $style), 'capitals and digits are unchanged');
    }

    public function testALabeledSmallCapsFaceIsPreferredAndDrawnAsWritten(): void
    {
        $fonts = new FontSet(self::$lato, faces: [new FamilyFace(self::$noto, 400, smallCaps: true)]);
        $formatter = new InlineFormatter(new ConversionOptions($fonts));
        [$commands] = $formatter->texts('Hello', new ComputedStyle(fontSize: 10, smallCaps: true), 0, 0, 'semantic');
        self::assertCount(1, $commands);
        self::assertSame(self::$noto, $commands[0]->font);
        self::assertSame(10.0, $commands[0]->size);
        self::assertFalse($commands[0]->capitals);
        [$normal] = $formatter->texts('Hello', new ComputedStyle(fontSize: 10), 0, 0, 'semantic');
        self::assertSame(self::$lato, $normal[0]->font);
    }

    public function testSmallCapsAndFontFallbackCompose(): void
    {
        $fonts = new FontSet(self::$noto, catalog: new FontCatalog(['Lato' => new FontSet(self::$lato)]));
        $diagnostics = new Diagnostics();
        $formatter = new InlineFormatter(new ConversionOptions($fonts), $diagnostics);
        $style = new ComputedStyle(fontSize: 10, fontFamily: new FontFamilyList([new FontFamilyName('Lato')]), smallCaps: true);
        // U+0377 and its capital exist in Noto Sans only; U+211D has no case.
        [$commands] = $formatter->texts("a\u{0377}\u{211D}B", $style, 0, 0, 'semantic');
        self::assertSame(
            [[self::$lato, 'a', 8.0, true], [self::$noto, "\u{0377}", 8.0, true], [self::$noto, "\u{211D}", 10.0, false], [self::$lato, 'B', 10.0, false]],
            array_map(static fn(Text $text): array => [$text->font, $text->text, $text->size, $text->capitals], $commands),
        );
        self::assertSame([], $diagnostics->all());
    }

    public function testALetterWithoutUppercaseGlyphIsDrawnAsWrittenAndReported(): void
    {
        $diagnostics = new Diagnostics();
        $formatter = new InlineFormatter(new ConversionOptions(new FontSet(self::$lato)), $diagnostics);
        [$commands] = $formatter->texts("a\u{0251}b", new ComputedStyle(fontSize: 10, smallCaps: true), 0, 0, 'semantic');
        self::assertSame([['a', 8.0, true], ["\u{0251}", 10.0, false], ['b', 8.0, true]], array_map(static fn(Text $text): array => [$text->text, $text->size, $text->capitals], $commands));
        self::assertSame(['font.small-caps-fallback'], array_column($diagnostics->all(), 'code'));
    }

    public function testSpacingAndLineBreakingUseTheReducedAdvances(): void
    {
        $formatter = new InlineFormatter(new ConversionOptions(new FontSet(self::$lato)));
        $plain = new ComputedStyle(fontSize: 10, smallCaps: true);
        $spaced = new ComputedStyle(fontSize: 10, smallCaps: true, textSpacing: new TextSpacing(letterSpacing: 1.5));
        // Spacing is an absolute length: it is not scaled with the synthesized capitals.
        self::assertEqualsWithDelta($formatter->advance('Straße', $plain) + 6 * 1.5, $formatter->advance('Straße', $spaced), 1e-9);

        $word = 'abc';
        $width = self::$lato->measure('ABC', 8);
        $space = self::$lato->measure(' ', 10);
        $style = new ComputedStyle(display: 'block', fontSize: 10, lineHeight: 12, smallCaps: true);
        $narrow = new ComputedStyle(display: 'block', fontSize: 10, lineHeight: 12, width: new Length(2 * $width + $space + 0.01), smallCaps: true);
        $paragraph = new Node('p', 'p', children: [new Node('t', '#text', text: $word . ' ' . $word . ' ' . $word, style: $narrow)], style: $narrow);
        $options = new ConversionOptions(new FontSet(self::$lato), new PageSettings(300, 200, new Edges(10, 10, 10, 10)));
        $result = (new LayoutEngine())->layout(new HtmlDocument(new Node('body', 'body', children: [$paragraph], style: $style)), $options, new Diagnostics());
        $lines = [];
        foreach ($result->pages[0]->commands as $command) {
            self::assertInstanceOf(Text::class, $command);
            $lines[(string) $command->y] = ($lines[(string) $command->y] ?? '') . $command->text;
        }
        self::assertSame([$word . ' ' . $word, $word], array_values($lines), 'two reduced words fit where two full-size words would not');
        self::assertGreaterThan(2 * $width + $space, 2 * self::$lato->measure('ABC', 10) + $space);
    }

    public function testThePdfDrawsScaledCapitalsAndExtractsTheLettersAsWritten(): void
    {
        $formatter = new InlineFormatter(new ConversionOptions(new FontSet(self::$lato)));
        [$commands] = $formatter->texts('Hxn ß', new ComputedStyle(fontSize: 10, smallCaps: true), 20, 40, 'text');
        $document = new PagedDocument([new LayoutPage(200, 100, $commands)], [new SemanticNode('root', 'Document'), new SemanticNode('text', 'Span', 'root')]);
        $read = (new DocumentReader())->read(new FileBytes((new Renderer())->render($document)));
        $stream = $read->resolve((new PageTreeReader($read))->read()->page(0)->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        $operators = $read->streamData($stream);
        self::assertSame(4, preg_match_all('#/F1 ([0-9.]+) Tf#', $operators, $sizes), 'one embedded font for all runs');
        self::assertSame(['10', '8', '10', '8'], $sizes[1]);
        self::assertSame(4, preg_match_all('/ActualText <FEFF([0-9A-F]+)>/', $operators, $actual));
        self::assertSame(['0048', '0078006E', '0020', '00DF'], $actual[1]);
        // "ß" draws two S glyphs: six glyph placements for H, X, N, space, S, S, all on one baseline.
        self::assertSame(6, preg_match_all('/ Tm/', $operators));
        self::assertSame(6, preg_match_all('/1 0 0 1 [0-9.]+ 60 Tm/', $operators));
    }
}
