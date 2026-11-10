<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Css\Declarations;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\FontSlant;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PHPUnit\Framework\Attributes\DataProvider;

/** CSS1 5.2.4: normal and small-caps, inherited, also set by the font shorthand. */
final class FontVariantTest extends CompilerTestCase
{
    public function testValuesAreInheritedAndCanBeReset(): void
    {
        // CSS1 5.2.4 example: an H3 in small-caps with emphasized words in oblique small-caps.
        $document = $this->compile(
            '<h3 id="h3">Title <em id="em">word</em> <span id="normal">plain</span> <span id="initial">reset</span></h3><p id="p">Body</p>',
            'h3{font-variant:Small-Caps}em{font-style:oblique}#normal{font-variant:normal}#initial{font-variant:initial}',
        );
        self::assertTrue($this->find($document->root, 'h3')->style->smallCaps);
        $em = $this->find($document->root, 'em')->style;
        self::assertTrue($em->smallCaps);
        self::assertSame(FontSlant::Oblique, $em->fontSlant());
        self::assertFalse($this->find($document->root, 'normal')->style->smallCaps);
        self::assertFalse($this->find($document->root, 'initial')->style->smallCaps);
        self::assertFalse($this->find($document->root, 'p')->style->smallCaps);
    }

    public function testFontShorthandSetsAndResetsTheVariant(): void
    {
        // CSS1 5.2.7 fifth example, then a shorthand without variant under a small-caps parent.
        $document = $this->compile('<div id="parent"><p id="a">A</p><p id="b">B</p><p id="c">C</p></div>', '#parent{font-size:20pt;font-variant:small-caps;font-style:italic;font-weight:bold}#a{font:normal small-caps 120%/120% fantasy}#b{font:12px serif}#c{font:small-caps bold oblique 10px/2 serif}');
        $a = $this->find($document->root, 'a')->style;
        self::assertTrue($a->smallCaps);
        self::assertSame(FontSlant::Normal, $a->fontSlant());
        self::assertSame(400, $a->fontWeight);
        self::assertSame(24.0, $a->fontSize);
        self::assertEqualsWithDelta(28.8, $a->lineHeight, 1e-12);
        self::assertSame('fantasy', $a->fontFamily->css());
        self::assertFalse($this->find($document->root, 'b')->style->smallCaps);
        $c = $this->find($document->root, 'c')->style;
        self::assertTrue($c->smallCaps);
        self::assertSame(700, $c->fontWeight);
        self::assertSame(FontSlant::Oblique, $c->fontSlant());
        self::assertSame(15.0, $c->lineHeight);
        self::assertSame('small-caps', (new Declarations(new Diagnostics()))->expand('font', 'Small-Caps 10px serif')['font-variant']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidVariants(): iterable
    {
        foreach (['all-small-caps', 'small caps', 'smallcaps', 'petite-caps', 'small-caps small-caps', 'uppercase', 'none'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidVariants')]
    public function testValuesOutsideTheCss1GrammarAreRejected(string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<p>A</p>', 'p{font-variant:' . $value . '}');
    }

    public function testInvalidVariantDoesNotEraseAnEarlierDeclarationInReportMode(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="subject">A</p>', '#subject{font-variant:small-caps;font-variant:petite-caps}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertTrue($this->find($document->root, 'subject')->style->smallCaps);
        self::assertCount(1, $diagnostics->all());
    }

    public function testAConvertedDocumentDrawsScaledCapitalsAndKeepsItsText(): void
    {
        $lato = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'));
        $html = '<!doctype html><html lang="en"><head><title>Small capitals</title><style>*{margin:0;padding:0}body{font:10pt/12pt serif}h3{font-size:10pt;font-weight:normal;font-variant:small-caps}</style></head><body><h3>Chapter One</h3><p>Chapter One</p></body></html>';
        $result = (new Converter())->convert(new HtmlSource($html), new ConversionOptions(new FontSet($lato), new PageSettings(300, 400, new Edges(20, 20, 20, 20))));
        self::assertSame(['font.default-family'], array_column($result->diagnostics, 'code'));
        $read = (new DocumentReader())->read(new FileBytes($result->pdf));
        $stream = $read->resolve((new PageTreeReader($read))->read()->page(0)->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        $operators = $read->streamData($stream);
        preg_match_all('#/F1 ([0-9.]+) Tf#', $operators, $sizes);
        preg_match_all('/ActualText <FEFF([0-9A-F]+)>/', $operators, $actual);
        $text = static fn(string $hex): string => (string) iconv('UTF-16BE', 'UTF-8', (string) hex2bin($hex));
        self::assertSame(['C', 'hapter', ' O', 'ne', 'Chapter One'], array_map($text, $actual[1]));
        self::assertSame(['10', '8', '10', '8', '10'], $sizes[1]);
    }
}
