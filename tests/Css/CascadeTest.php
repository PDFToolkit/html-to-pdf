<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class CascadeTest extends CompilerTestCase
{
    public function testSpecificityIsLexicographicRatherThanDecimalWeighted(): void
    {
        $classes = implode(' ', array_map(static fn($i) => 'a' . $i, range(1, 12)));
        $selector = '.' . str_replace(' ', '.', $classes);
        $document = $this->compile('<p id="x" class="' . $classes . '">X</p>', '#x {color:red}' . $selector . '{color:blue}');
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testImportantAndInlinePriorities(): void
    {
        $document = $this->compile('<p id="a" style="color:blue">A</p><p id="b" style="color:blue!important">B</p>', 'p {color:red !important}');
        self::assertSame(1.0, $this->find($document->root, 'a')->style->color->red);
        self::assertSame(1.0, $this->find($document->root, 'b')->style->color->blue);
    }

    public function testSourceOrderAndShorthandLonghandCascade(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{margin-left:10pt!important;margin:1pt 2pt 3pt 4pt;color:red} p{margin-top:5pt;color:blue}');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame([5.0, 2.0, 3.0, 10.0], [$style->margin->top, $style->margin->right, $style->margin->bottom, $style->margin->left]);
        self::assertSame(1.0, $style->color->blue);
    }

    public function testRelativeFontsAndUnitlessLineHeightInheritance(): void
    {
        $document = $this->compile('<div style="font-size:20px;line-height:2;color:red"><span id="x" style="font-size:150%">X</span></div>');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(22.5, $style->fontSize);
        self::assertSame(45.0, $style->lineHeight);
        self::assertSame(1.0, $style->color->red);
    }

    public function testLengthLineHeightInheritsComputedLength(): void
    {
        $document = $this->compile('<div style="font-size:10pt;line-height:2em"><span id="x" style="font-size:30pt">X</span></div>');
        self::assertSame(20.0, $this->find($document->root, 'x')->style->lineHeight);
    }

    public function testGlobalKeywordsAndRootRelativeLengths(): void
    {
        $document = $this->compile('<html style="font-size:20pt"><body><div style="color:red;padding:3pt"><span id="x" style="color:initial;padding:inherit;font-size:2rem">X</span></div></body></html>');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(40.0, $style->fontSize);
        self::assertSame(0.0, $style->color->red);
        self::assertSame(3.0, $style->padding->left);
    }

    public function testPrintMediaAndStructuralSelectors(): void
    {
        $document = $this->compile('<p id="x">X</p><p id="y">Y</p>', '@media screen{p{color:red}} @media print{body > p:first-child{color:blue}} @media not print{p{color:green}}');
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->blue);
        self::assertSame(0.0, $this->find($document->root, 'y')->style->color->blue);
    }

    public function testLiteralColonInAttributeSelectorIsNotAPseudoClass(): void
    {
        $document = $this->compile('<p id="x" title="a:hover">X</p>', 'p[title="a:hover"]{color:blue}');
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->blue);
    }

    public function testTypedTableStyleAndPresentationalDimensions(): void
    {
        $document = $this->compile('<table id="t"><tr><td id="c" width="25%">X</td></tr></table>', 'table{table-layout:fixed} td{vertical-align:middle;border:1pt solid #abc}');
        self::assertSame('fixed', $this->find($document->root, 't')->style->tableLayout);
        $style = $this->find($document->root, 'c')->style;
        self::assertSame('middle', $style->verticalAlign);
        self::assertSame(100.0, $style->width?->resolve(400));
        self::assertSame(1.0, $style->borderWidth);
        self::assertEqualsWithDelta(170 / 255, $style->borderColor->red, .00001);
    }

    public function testReportModeDiagnosesUnsupportedFeatures(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="x">X</p>', 'p{display:grid;color:red} @page{size:A5}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertSame('block', $this->find($document->root, 'x')->style->display);
        self::assertSame(['css.at-rule', 'css.value'], array_map(static fn($item) => $item->code, $diagnostics->all()));
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedCss(): iterable
    {
        yield 'dynamic selector' => ['p:hover{color:red}'];
        yield 'pseudo element' => ['p::before{color:red}'];
        yield 'import' => ['@import "https://unused.invalid/x.css";'];
        yield 'page' => ['@page{size:A4}'];
        yield 'font face' => ['@font-face{font-family:x;src:url(file:///x.ttf)}'];
        yield 'positioning' => ['p{position:absolute}'];
        yield 'font family' => ['p{font-family:serif}'];
        yield 'flex' => ['p{display:flex}'];
        yield 'negative padding' => ['p{padding:-1pt}'];
        yield 'negative margin' => ['p{margin:-1pt}'];
        yield 'unknown unit' => ['p{width:1vw}'];
        yield 'zero font' => ['p{font-size:0}'];
        yield 'inline alignment' => ['p{vertical-align:middle}'];
        yield 'height percentage' => ['p{height:50%}'];
        yield 'unknown media' => ['@media (max-width:400px){p{color:red}}'];
    }

    #[DataProvider('unsupportedCss')]
    public function testUnsupportedCssIsExplicit(string $css): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<p>X</p>', $css);
    }

    /** @return iterable<string,array{string,ConversionLimits}> */
    public static function cssLimits(): iterable
    {
        yield 'bytes' => ['p{color:red}', new ConversionLimits(maxCssBytes: 5)];
        yield 'rules and declarations' => ['p{color:red;}', new ConversionLimits(maxRules: 1)];
        yield 'recursive parsing' => ['p{color:rgb((((((1))))))}', new ConversionLimits(maxDepth: 5)];
        yield 'selector work' => ['body p:first-child{color:red}', new ConversionLimits(maxSelectorMatches: 2)];
    }

    #[DataProvider('cssLimits')]
    public function testCssWorkIsBoundedBeforeParsingAndMatching(string $css, ConversionLimits $limits): void
    {
        $this->expectException(LimitExceeded::class);
        $this->compile('<p>X</p>', $css, $this->options($limits));
    }

    public function testCssByteLimitIsCumulativeAcrossSources(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->compile('<style>p{color:red}</style><p style="color:blue">X</p>', 'p{font-size:12pt}', $this->options(new ConversionLimits(maxCssBytes: 30)));
    }

    public function testImportantValueRemainsProtectedAgainstDuplicateDeclarations(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{color:blue!important; color:red;}');
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->blue);
    }

    public function testUnitConversionAndCurrentColorBorder(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{color:blue;width:1in;padding:2.54cm;border-width:1px;border-style:solid}');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(72.0, $style->width?->value);
        self::assertEqualsWithDelta(72.0, $style->padding->left, .000001);
        self::assertSame(.75, $style->borderWidth);
        self::assertSame(1.0, $style->borderColor->blue);
    }
}
