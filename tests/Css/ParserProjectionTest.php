<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Css\ParserProjection;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class ParserProjectionTest extends CompilerTestCase
{
    public function testSignedRgbComponentsCompileWithoutLosingOriginalValues(): void
    {
        $document = $this->compile('<p id="x">X</p>', '/* é 😀 */p{color:RGB(+255,+0,-0);background-color:rgb(+100%,+0.5%,+0%)}');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame([1.0, 0.0, 0.0], [$style->color->red, $style->color->green, $style->color->blue]);
        self::assertSame([1.0, 0.005, 0.0], [$style->background?->red, $style->background?->green, $style->background?->blue]);
    }

    public function testInlineProjectionAndUnicodeOffsetsUseOriginalSource(): void
    {
        $document = $this->compile('<p id="x" style="/* é 😀 */ color:rgb(+255,+0,-0);font-size:17pt">X</p>');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(17.0, $style->fontSize);
    }

    public function testProjectionPreservesLengthsAndTouchesOnlyRgbNumberSigns(): void
    {
        $source = '/* rgb(+1,+2,+3) é 😀 */p{color:rgb(+255,+.5%,+0);other:"rgb(+1,+2,+3)";a:calc(1 +2);b:calc(rgb(+1,+2,+3));c:url(rgb(+1,+2,+3));d:rgb(+ 1,+ 2,+ 3);e:r\\67 b(+1,+2,+3);f:myrgb(+1,+2,+3);g:rgba(+1,+2,+3,+1)}';
        $expected = str_replace('color:rgb(+255,+.5%,+0)', 'color:rgb( 255, .5%, 0)', $source);
        $projected = (new ParserProjection(new Diagnostics(UnsupportedPolicy::Reject)))->project($source);
        self::assertSame($expected, $projected);
        self::assertSame(strlen($source), strlen($projected));
        self::assertSame(mb_strlen($source, 'UTF-8'), mb_strlen($projected, 'UTF-8'));
    }

    public function testInvalidSignedRawValueCannotBecomeValidThroughProjection(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{color:red;color:rgb(+1..2,+0,+0);background-color:blue}', $this->options(policy: UnsupportedPolicy::Report));
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(1.0, $style->background?->blue);
    }

    public function testValidInitialCharsetKeepsUtf8SourceOffsets(): void
    {
        $document = $this->compile('<p id="x" class="é">X</p>', '@charset "UTF-8"; /* 😀 */ .é{color:rgb(+255,+0,-0);font-size:19pt}');
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(19.0, $style->fontSize);
    }

    public function testCharsetTextInCommentsStringsAndUrlsIsNotADirective(): void
    {
        $css = '/* @charset "x-bogus"; */ p{unknown:"@charset \'x-bogus\';";other:url(@charset.css);color:red}';
        $projection = (new ParserProjection(new Diagnostics(UnsupportedPolicy::Reject)))->project($css);
        self::assertSame($css, $projection);
    }

    #[DataProvider('invalidCharsets')]
    public function testReportIgnoresInvalidEncodingSheetWithoutChangingParserEncoding(string $css): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<style>p{color:blue}</style><p id="x">X</p>', $css, $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->blue);
        self::assertSame(['css.charset'], array_map(static fn($item) => $item->code, $diagnostics->all()));
    }

    #[DataProvider('invalidCharsets')]
    public function testRejectRaisesDomainExceptionForInvalidEncodingSheet(string $css): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('UTF-8 @charset');
        $this->compile('<p>X</p>', $css);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidCharsets(): iterable
    {
        yield 'unknown label' => ['@charset "x-bogus";p{color:red}'];
        yield 'known non-UTF8' => ['@charset "ISO-8859-1";p{color:red}'];
        yield 'empty label' => ['@charset "";p{color:red}'];
        yield 'escaped directive' => ['@\\63harset "x-bogus";p{color:red}'];
        yield 'uppercase directive' => ['@CHARSET "x-bogus";p{color:red}'];
        yield 'repeated directive' => ['@charset "UTF-8";@charset "UTF-8";p{color:red}'];
        yield 'late directive' => ['p{color:red}@charset "UTF-8";'];
        yield 'nested directive' => ['@media print{@charset "UTF-8";p{color:red}}'];
        yield 'unquoted label' => ['@charset UTF-8;p{color:red}'];
        yield 'missing terminator' => ['@charset "UTF-8" p{color:red}'];
        yield 'after unmatched parenthesis' => ['broken(; } @charset "x-bogus";p{color:red}'];
        yield 'after unmatched bracket' => ['broken[; } @charset "x-bogus";p{color:red}'];
    }

    public function testSourceBudgetIsChargedBeforeProjection(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->compile('<p>X</p>', 'p{color:rgb(+255,+0,+0)}', $this->options(limits: new ConversionLimits(maxCssBytes: 8)));
    }
}
