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
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\PageTreeReader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;
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

    /** @return iterable<string, array{string, int, int}> */
    public static function blockLinkContents(): iterable
    {
        yield 'direct text' => ['Visit', 1, 1];
        yield 'paragraph descendant' => ['<p>Visit</p>', 1, 1];
        yield 'nested block and inline descendant' => ['<div><p><span>Visit</span></p></div>', 1, 1];
        yield 'list marker and body' => ['<ol><li>Visit</li></ol>', 2, 1];
        yield 'table caption and cell' => ['<table style="table-layout:fixed"><caption>Visit</caption><tbody><tr><td>Visit</td></tr></tbody></table>', 2, 1];
        yield 'paragraphs across pages' => ['<p>First</p><p style="break-before:page">Second</p>', 2, 2];
    }

    #[DataProvider('blockLinkContents')]
    public function testBlockLinksPreserveTheirUriAndStructureAcrossDescendants(string $content, int $annotationCount, int $pageCount): void
    {
        $uri = 'https://example.com/visit?from=pdf';
        $result = (new Converter())->convert(
            $this->source('<a href="' . $uri . '" style="display:block">' . $content . '</a><p>Outside</p>'),
            new ConversionOptions($this->fonts()),
        );
        self::assertSame([], $result->diagnostics);
        self::assertSame($pageCount, $result->pageCount);
        $read = (new DocumentReader())->read(new FileBytes($result->pdf));
        $inspection = (new DocumentInspector())->inspect($read)->jsonSerialize();
        self::assertSame([], $inspection['diagnostics']);
        self::assertFalse($inspection['truncated']);
        $links = array_values(array_filter($inspection['nodes'], static fn(array $node): bool => $node['role'] !== null && ($node['role']->jsonSerialize()['preview'] ?? null) === '/Link'));
        self::assertCount(1, $links);
        $objects = array_values(array_filter($inspection['nodes'], static fn(array $node): bool => $node['kind'] === 'objr'));
        self::assertCount($annotationCount, $objects);
        foreach ($objects as $object) {
            self::assertSame($links[0]['id'], $object['parentId']);
        }
        $pages = (new PageTreeReader($read))->read();
        $count = 0;
        for ($index = 0; $index < $pageCount; ++$index) {
            $annotations = $read->resolve($pages->page($index)->dictionary->get('Annots'));
            self::assertInstanceOf(ArrayType::class, $annotations);
            foreach ($annotations as $reference) {
                $annotation = $read->resolve($reference);
                self::assertInstanceOf(DictionaryType::class, $annotation);
                self::assertEquals(new NameType('Link'), $annotation->get('Subtype'));
                self::assertInstanceOf(IntegerType::class, $annotation->get('StructParent'));
                $action = $read->resolve($annotation->get('A'));
                self::assertInstanceOf(DictionaryType::class, $action);
                self::assertEquals(new NameType('URI'), $action->get('S'));
                $target = $action->get('URI');
                self::assertInstanceOf(StringType::class, $target);
                self::assertSame($uri, $target->value);
                ++$count;
            }
        }
        // The following paragraph must not inherit the preceding block's link.
        self::assertSame($annotationCount, $count);
    }

    public function testUnsafeBlockLinkTargetIsRejectedInStrictMode(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Converter())->convert($this->source('<a href="javascript:alert(1)" style="display:block"><p>Visit</p></a>'), new ConversionOptions($this->fonts()));
    }

    public function testUnsafeBlockLinkTargetIsRemovedWithADiagnosticInReportMode(): void
    {
        $result = (new Converter())->convert(
            $this->source('<a href="javascript:alert(1)" style="display:block"><p>Visit</p></a>'),
            new ConversionOptions($this->fonts(), unsupported: UnsupportedPolicy::Report),
        );
        self::assertSame(['html.link-target'], array_column($result->diagnostics, 'code'));
        $read = (new DocumentReader())->read(new FileBytes($result->pdf));
        $page = (new PageTreeReader($read))->read()->page(0);
        $annotations = $read->resolve($page->dictionary->get('Annots'));
        self::assertInstanceOf(ArrayType::class, $annotations);
        self::assertCount(0, $annotations);
    }

    /** @return iterable<string, array{string}> */
    public static function linkedImageDisplays(): iterable
    {
        yield 'inline image' => ['inline'];
        yield 'block image' => ['block'];
    }

    #[DataProvider('linkedImageDisplays')]
    public function testImagesInsideBlockLinksRemainExplicitlyUnsupported(string $display): void
    {
        $image = new ImageAsset(file_get_contents(__DIR__ . '/Fixtures/images/rgba.png'));
        $options = new ConversionOptions($this->fonts(), resources: new ResourceCatalog(images: ['image' => $image]));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('Linked images');
        (new Converter())->convert($this->source('<a href="https://example.com" style="display:block"><img src="image" alt="Image" style="display:' . $display . '"></a>'), $options);
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
