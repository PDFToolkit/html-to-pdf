<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\PageTreeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    private function fonts(): FontSet
    {
        return new FontSet(new FontFace(file_get_contents(__DIR__ . '/Fixtures/fonts/NotoSans-Regular.ttf')));
    }

    private function source(string $body, string $css = ''): HtmlSource
    {
        return new HtmlSource('<!doctype html><html lang="fr"><head><title>Épreuve</title></head><body>' . $body . '</body></html>', $css);
    }

    public function testThePublicFacadeProducesOneInspectibleTaggedDocument(): void
    {
        $result = (new Converter())->convert($this->source('<p>Bonjour, été.</p>'), new ConversionOptions($this->fonts()));
        self::assertSame(1, $result->pageCount);
        self::assertSame([], $result->diagnostics);
        $read = (new DocumentReader())->read(new FileBytes($result->pdf));
        $inspection = (new DocumentInspector())->inspect($read)->jsonSerialize();
        self::assertFalse($inspection['truncated']);
        self::assertSame([], $inspection['diagnostics']);
        self::assertCount(1, $inspection['pages']);
    }

    public function testConversionStateDoesNotLeakWhenTheFacadeAndFontsAreReused(): void
    {
        $options = new ConversionOptions($this->fonts());
        $converter = new Converter();
        $converter->convert($this->source('<p>Premier document, avec un lien <a href="https://example.org/">externe</a>.</p>'), $options);
        $second = $converter->convert($this->source('<p>Second document.</p>'), $options);
        $fresh = (new Converter())->convert($this->source('<p>Second document.</p>'), $options);
        self::assertSame($fresh->pdf, $second->pdf);
        self::assertStringNotContainsString('/URI', $second->pdf);
    }

    /** @return iterable<string, array{string}> */
    public static function captionDeclarations(): iterable
    {
        yield 'native caption display' => [''];
        yield 'explicit caption display' => ['caption { display: table-caption; }'];
    }

    #[DataProvider('captionDeclarations')]
    public function testTableCaptionIsRenderedAboveItsRowWithPreservedUnicode(string $declaration): void
    {
        $source = $this->source(
            '<table><caption>Légende</caption><tbody><tr><td>Donnée</td></tr></tbody></table>',
            'body { font-size: 10pt; line-height: 16pt; } table { table-layout: fixed; } ' . $declaration,
        );
        $result = (new Converter())->convert($source, new ConversionOptions($this->fonts()));
        self::assertSame(1, $result->pageCount);
        self::assertSame([], $result->diagnostics);
        $read = (new DocumentReader())->read(new FileBytes($result->pdf));
        $inspection = (new DocumentInspector())->inspect($read)->jsonSerialize();
        self::assertSame([], $inspection['diagnostics']);
        self::assertFalse($inspection['truncated']);
        self::assertCount(2, array_filter($inspection['nodes'], static fn(array $node): bool => $node['kind'] === 'mcr'));
        $page = (new PageTreeReader($read))->read()->page(0);
        $contents = $read->resolve($page->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $contents);
        $operators = $read->streamData($contents);
        preg_match_all('/\/ActualText\s*<([[:xdigit:]]+)>/', $operators, $actualTexts);
        self::assertSame(
            ['Légende', 'Donnée'],
            array_map(static fn(string $hex): string => mb_convert_encoding(substr((string) hex2bin($hex), 2), 'UTF-8', 'UTF-16BE'), $actualTexts[1]),
        );
        preg_match_all('/1 0 0 1 [-0-9.]+ ([-0-9.]+) Tm/', $operators, $positions);
        self::assertNotEmpty($positions[1]);
        // PDF coordinates start at the bottom: the caption's baseline must be one line higher.
        self::assertEqualsWithDelta(16.0, (float) $positions[1][0] - (float) $positions[1][array_key_last($positions[1])], 0.001);
    }

    public function testHiddenContentDoesNotRequireItsMissingFontVariant(): void
    {
        $result = (new Converter())->convert($this->source('<strong hidden>Invisible</strong><p>Visible</p>'), new ConversionOptions($this->fonts()));
        self::assertSame(1, $result->pageCount);
        self::assertSame([], $result->diagnostics);
    }

    public function testUnsupportedStyleIsRejectedByDefault(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Converter())->convert($this->source('<div>Unsupported grid</div>', 'div { display: grid; }'), new ConversionOptions($this->fonts()));
    }

    public function testReportModeKeepsItsExplicitDiagnostic(): void
    {
        $result = (new Converter())->convert($this->source('<div>Reported grid</div>', 'div { display: grid; }'), new ConversionOptions($this->fonts(), unsupported: UnsupportedPolicy::Report));
        self::assertSame('css.value', $result->diagnostics[0]->code);
        self::assertStringStartsWith('%PDF-1.7', $result->pdf);
    }

    public function testMissingResourcesRemainErrorsInReportMode(): void
    {
        $this->expectException(ConversionException::class);
        (new Converter())->convert($this->source('<img src="https://invalid.example/image.png" alt="Missing">'), new ConversionOptions($this->fonts(), unsupported: UnsupportedPolicy::Report));
    }

    public function testPageLimitIsEnforcedAcrossTheWholePipeline(): void
    {
        $options = new ConversionOptions($this->fonts(), page: new PageSettings(200, 200, new Edges(20, 20, 20, 20)), limits: new ConversionLimits(maxPages: 1));
        $this->expectException(LimitExceeded::class);
        (new Converter())->convert($this->source('<p>First page.</p><p style="break-before:page">Second page.</p>'), $options);
    }
}
