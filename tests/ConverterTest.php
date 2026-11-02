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
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    private function fonts(): FontSet
    {
        return new FontSet(new FontFace(file_get_contents(__DIR__.'/Fixtures/fonts/NotoSans-Regular.ttf')));
    }

    private function source(string $body, string $css = ''): HtmlSource
    {
        return new HtmlSource('<!doctype html><html lang="fr"><head><title>Épreuve</title></head><body>'.$body.'</body></html>', $css);
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
