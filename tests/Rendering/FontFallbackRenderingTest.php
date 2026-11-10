<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Rendering;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Style\Edges;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PHPUnit\Framework\TestCase;

/** A fallback run is a real second embedded font, positioned where the first run ends. */
final class FontFallbackRenderingTest extends TestCase
{
    public function testEachRunIsDrawnWithItsOwnEmbeddedFontAndKeepsItsUnicodeText(): void
    {
        $noto = new FontFace(file_get_contents(dirname(__DIR__) . '/Fixtures/fonts/NotoSans-Regular.ttf'));
        $lato = new FontFace(file_get_contents(dirname(__DIR__, 2) . '/examples/assets/fonts/Lato-Regular.ttf'));
        $fonts = new FontSet($noto, catalog: new FontCatalog(['Lato' => new FontSet($lato)]));
        $html = '<!doctype html><html lang="en"><head><title>Fallback</title><style>*{margin:0;padding:0}body{font:10pt/12pt Lato}</style></head><body><p><a href="https://example.test/">a' . "\u{211D}" . 'b</a></p></body></html>';
        $result = (new Converter())->convert(new HtmlSource($html), new ConversionOptions($fonts, new PageSettings(300, 400, new Edges(20, 20, 20, 20))));
        self::assertSame([], $result->diagnostics);

        $read = (new DocumentReader())->read(new FileBytes($result->pdf));
        $page = (new PageTreeReader($read))->read()->page(0);
        $resources = $read->resolve($page->dictionary->get('Resources'));
        self::assertInstanceOf(DictionaryType::class, $resources);
        $fontResources = $read->resolve($resources->get('Font'));
        self::assertInstanceOf(DictionaryType::class, $fontResources);
        self::assertCount(2, iterator_to_array($fontResources), 'the fallback glyph embeds a second font program');

        $stream = $read->resolve($page->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        $operators = $read->streamData($stream);
        self::assertSame(3, preg_match_all('#/(F[0-9]+) 10 Tf#', $operators, $selected));
        self::assertSame($selected[1][0], $selected[1][2]);
        self::assertNotSame($selected[1][0], $selected[1][1]);
        self::assertSame(3, preg_match_all('/ActualText <FEFF([0-9A-F]+)>/', $operators, $actual));
        self::assertSame(['0061', '211D', '0062'], $actual[1], 'extraction keeps the source characters in reading order');
        self::assertSame(3, preg_match_all('/1 0 0 1 ([0-9.]+) ([0-9.]+) Tm/', $operators, $positions));
        self::assertEqualsWithDelta(20, (float) $positions[1][0], 1e-6);
        self::assertEqualsWithDelta(20 + $lato->measure('a', 10), (float) $positions[1][1], 1e-4);
        self::assertEqualsWithDelta(20 + $lato->measure('a', 10) + $noto->measure("\u{211D}", 10), (float) $positions[1][2], 1e-4);
        self::assertCount(1, array_unique($positions[2]), 'one baseline');

        $annotations = $read->resolve($page->dictionary->get('Annots'));
        self::assertInstanceOf(ArrayType::class, $annotations);
        self::assertCount(3, $annotations, 'one link rectangle per run, as for a link wrapped over several lines');
    }
}
