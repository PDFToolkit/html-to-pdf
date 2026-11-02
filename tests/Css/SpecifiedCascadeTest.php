<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Css\Cascade;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Parsing\ParseBudget;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecifiedCascadeTest extends TestCase
{
    /** @return iterable<string,array{string,string,string}> */
    public static function invalidOverrides(): iterable
    {
        yield 'unrecognized color' => ['color:red;color:bogus', 'color', 'red'];
        yield 'malformed RGB token' => ['color:red;color:rgb(0..1,0,0)', 'color', 'red'];
        yield 'invalid hexadecimal digit' => ['color:red;color:#gg0000', 'color', 'red'];
        yield 'mixed RGB units' => ['color:red;color:rgb(10%,0,0)', 'color', 'red'];
        yield 'fractional integer RGB' => ['color:red;color:rgb(0.5,0,0)', 'color', 'red'];
        yield 'malformed length token' => ['width:5pt;width:1..2pt', 'width', '5pt'];
        yield 'unsupported value' => ['text-align:right;text-align:justify', 'text-align', 'right'];
        yield 'invalid important value' => ['color:red;color:bogus!important', 'color', 'red'];
    }

    #[DataProvider('invalidOverrides')]
    public function testInvalidSpecifiedValuesCannotWinTheCascade(string $css, string $property, string $expected): void
    {
        [$cascade, $element, $diagnostics] = $this->cascade();
        $cascade->stylesheet('p{' . $css . '}');
        self::assertSame($expected, $cascade->forElement($element)[$property]);
        self::assertNotEmpty($diagnostics->all());
    }

    public function testInvalidInlineStyleCannotMaskValidStylesheet(): void
    {
        [$cascade, $element] = $this->cascade();
        $cascade->stylesheet('p{color:red;width:7pt}');
        $cascade->inline($element, 'color:bogus!important;width:1..2pt');
        self::assertSame(['color' => 'red', 'width' => '7pt'], $cascade->forElement($element));
    }

    public function testShorthandFailureKeepsEveryPreviousSide(): void
    {
        [$cascade, $element] = $this->cascade();
        $cascade->stylesheet('p{margin:1pt 2pt 3pt 4pt;margin:5pt bogus;padding:6pt;padding:7pt 8pt 9pt inherit}');
        self::assertSame([
            'margin-top' => '1pt', 'margin-right' => '2pt', 'margin-bottom' => '3pt', 'margin-left' => '4pt',
            'padding-top' => '6pt', 'padding-right' => '6pt', 'padding-bottom' => '6pt', 'padding-left' => '6pt',
        ], $cascade->forElement($element));
    }

    public function testInvalidBorderShorthandDoesNotResetAnyLonghand(): void
    {
        [$cascade, $element] = $this->cascade();
        $cascade->stylesheet('p{border-left:2pt dashed red;border-left:3pt solid bogus}');
        $values = $cascade->forElement($element);
        self::assertCount(3, $values);
        self::assertSame('2pt', $values['border-left-width']);
        self::assertSame('dashed', $values['border-left-style']);
        self::assertSame('red', $values['border-left-color']);
    }

    public function testRgbComponentsRemainExactUntilComputedColorClipping(): void
    {
        [$cascade, $element, $diagnostics] = $this->cascade();
        $cascade->stylesheet('p{color:rgb(-20,260,0);background:rgb(12.123456789%,0%,100%)}');
        self::assertSame(['color' => 'rgb(-20,260,0)', 'background-color' => 'rgb(12.123456789%,0%,100%)'], $cascade->forElement($element));
        self::assertSame([], $diagnostics->all());
    }

    /** @return array{Cascade,\DOMElement,Diagnostics} */
    private function cascade(): array
    {
        $document = new \DOMDocument();
        $document->loadHTML('<html><body><p id="x">Text</p></body></html>');
        $element = $document->getElementById('x');
        self::assertInstanceOf(\DOMElement::class, $element);
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        return [new Cascade($document, new ParseBudget(new ConversionLimits()), $diagnostics), $element, $diagnostics];
    }
}
