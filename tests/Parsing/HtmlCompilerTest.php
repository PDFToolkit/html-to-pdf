<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Parsing;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class HtmlCompilerTest extends CompilerTestCase
{
    public function testHtml5RepairAndUnicodeMetadata(): void
    {
        $document = $this->compile('<!doctype html><html lang="fr"><head><title>Caf&eacute; &amp; th&eacute;</title></head><body><p id="p">A<div id="d">B</div><p id="q">C&nbsp;D</body></html>');
        self::assertSame('Café & thé', $document->title);
        self::assertSame('fr', $document->language);
        self::assertSame('body', $document->root->tag);
        self::assertSame(['p', 'div', 'p'], array_map(static fn($node) => $node->tag, $document->root->children));
        self::assertSame("C\u{00A0}D", $this->find($document->root, 'q')->children[0]->text);
    }

    public function testFragmentGetsBodyBeforeSelectorMatching(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'body > p { color: blue; }');
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->blue);
    }

    public function testLinkedStylesheetsOnlyUseExactSuppliedBytes(): void
    {
        $resources = new ResourceCatalog(stylesheets: ['https://unused.invalid/site.css' => 'p {color:red}']);
        $document = $this->compile('<link rel="stylesheet" href="https://unused.invalid/site.css"><p id="x">X</p>', options: $this->options(resources: $resources));
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
        $this->expectException(ConversionException::class);
        $this->compile('<link rel="stylesheet" href="https://unused.invalid/missing.css"><p>X</p>', options: $this->options(resources: $resources));
    }

    public function testScreenResourcesAreNotResolvedForPrint(): void
    {
        $document = $this->compile('<link rel="stylesheet" media="screen" href="file:///not-available.css"><p id="x">X</p>');
        self::assertSame(0.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testHiddenAncestorDoesNotRequireUnusedImages(): void
    {
        $document = $this->compile('<div style="display:none"><img src="file:///not-supplied.png"></div><p id="x">Visible</p>');
        self::assertSame('Visible', $this->find($document->root, 'x')->children[0]->text);
    }

    public function testHiddenHtmlRemainsHiddenAfterBodyNormalization(): void
    {
        $document = $this->compile('<html style="display:none"><body style="display:block"><img src="not-supplied"></body></html>');
        self::assertSame('none', $document->root->style->display);
    }

    public function testHiddenAttributeDoesNotRenderOrResolveResources(): void
    {
        $document = $this->compile('<div id="hidden" hidden><img src="not-supplied"></div><p id="shown" hidden style="display:block">Shown</p>');
        self::assertSame('none', $this->find($document->root, 'hidden')->style->display);
        self::assertSame('block', $this->find($document->root, 'shown')->style->display);
        $root = $this->compile('<html hidden><body style="display:block"><img src="not-supplied"></body></html>');
        self::assertSame('none', $root->root->style->display);
    }

    public function testReportModeReportsUnsupportedAccessibilitySemantics(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="x" role="heading" aria-level="2" aria-label="Different">Original</p>', options: $this->options(policy: UnsupportedPolicy::Report), diagnostics: $diagnostics);
        self::assertSame('p', $this->find($document->root, 'x')->tag);
        self::assertSame(['html.aria', 'html.aria', 'html.aria'], array_map(static fn($item) => $item->code, $diagnostics->all()));
    }

    public function testReportModeKeepsTextAndDropsExecutableInputs(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<script>throw new Error("must not run")</script><p onclick="bad()"><a id="a" href="javascript:bad()">Café</a></p>', options: $this->options(policy: UnsupportedPolicy::Report), diagnostics: $diagnostics);
        $link = $this->find($document->root, 'a');
        self::assertNull($link->attribute('href'));
        self::assertSame('Café', $link->children[0]->text);
        self::assertSame(['html.tag', 'html.attribute', 'html.link-target'], array_map(static fn($item) => $item->code, $diagnostics->all()));
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedMarkup(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'form' => ['<form><p>Value</p></form>'];
        yield 'mathml' => ['<math><mi>x</mi></math>'];
        yield 'superscript' => ['<p>x<sup>2</sup></p>'];
        yield 'span' => ['<table><tr><td colspan="2">A</td></tr></table>'];
        yield 'direction' => ['<p dir="rtl">Text</p>'];
        yield 'event' => ['<p onmouseover="x()">Text</p>'];
        yield 'fragment destination' => ['<a href="#x">Text</a>'];
        yield 'responsive image' => ['<img src="a" srcset="b 2x">'];
        yield 'table group scope' => ['<table><tr><th scope="rowgroup">X</th></tr></table>'];
        yield 'role' => ['<p role="heading">Heading</p>'];
        yield 'aria hidden' => ['<p aria-hidden="true">Text</p>'];
        yield 'aria label' => ['<p aria-label="Different">Text</p>'];
        yield 'hidden until found' => ['<p hidden="until-found">Text</p>'];
    }

    #[DataProvider('unsupportedMarkup')]
    public function testUnsupportedMarkupIsExplicit(string $html): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile($html);
    }

    /** @return iterable<string,array{string,ConversionLimits}> */
    public static function excessiveInput(): iterable
    {
        yield 'bytes' => ['<p>123456</p>', new ConversionLimits(maxInputBytes: 8)];
        yield 'text' => ['<p>éééé</p>', new ConversionLimits(maxTextCharacters: 3)];
        yield 'nodes' => ['<p>A</p><p>B</p>', new ConversionLimits(maxNodes: 3)];
        yield 'depth' => [str_repeat('<div>', 10), new ConversionLimits(maxDepth: 4)];
        yield 'normalized body depth' => ['<div><span>X</span></div>', new ConversionLimits(maxDepth: 4)];
        yield 'attributes' => ['<p a="1" b="2" c="3" d="4">X</p>', new ConversionLimits(maxNodes: 4)];
    }

    #[DataProvider('excessiveInput')]
    public function testLimitsAreEnforcedDuringParsing(string $html, ConversionLimits $limits): void
    {
        $this->expectException(LimitExceeded::class);
        $this->compile($html, options: $this->options($limits));
    }

    public function testInvalidUtf8IsRejectedBeforeHtmlParsing(): void
    {
        $this->expectException(ConversionException::class);
        $this->compile("<p>\xff</p>");
    }

    public function testCompilerStateDoesNotLeakBetweenDocuments(): void
    {
        $compiler = new HtmlCompiler();
        $options = $this->options();
        $first = $compiler->parse(new HtmlSource('<p id="x">A</p>', '#x { color: red }'), $options, new Diagnostics());
        $second = $compiler->parse(new HtmlSource('<p id="x">B</p>'), $options, new Diagnostics());
        self::assertSame(1.0, $this->find($first->root, 'x')->style->color->red);
        self::assertSame(0.0, $this->find($second->root, 'x')->style->color->red);
        self::assertSame($first->root->id, $second->root->id);
    }
}
