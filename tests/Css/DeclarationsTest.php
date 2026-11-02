<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\Declarations;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeclarationsTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'unknown color' => ['color', 'not-a-color'];
        yield 'mixed RGB units' => ['background-color', 'rgb(10%,20,30%)'];
        yield 'malformed number' => ['width', '1..2pt'];
        yield 'trailing decimal point' => ['width', '1.pt'];
        yield 'exponent is outside profile' => ['width', '1e2pt'];
        yield 'unknown length unit' => ['width', '3vw'];
        yield 'unitless nonzero length' => ['width', '3'];
        yield 'empty shorthand' => ['margin', ''];
        yield 'invalid fourth component' => ['margin', '1pt 2pt 3pt invalid'];
        yield 'negative padding' => ['padding', '1pt -2pt'];
        yield 'mixed global keyword' => ['margin', 'inherit 1pt'];
        yield 'five component shorthand' => ['padding', '1pt 2pt 3pt 4pt 5pt'];
        yield 'invalid border color' => ['border', '2pt solid not-a-color'];
        yield 'duplicate border width' => ['border', '1pt 2pt solid red'];
        yield 'invalid border tuple' => ['border-width', '1pt bogus 3pt'];
        yield 'zero font size' => ['font-size', '0'];
        yield 'negative line height' => ['line-height', '-1'];
        yield 'line height bound' => ['line-height', '101'];
        yield 'invalid widow count' => ['widows', '1.5'];
        yield 'unbounded widow count' => ['widows', '10000'];
        yield 'invalid display' => ['display', 'inline-grid'];
    }

    #[DataProvider('invalidDeclarations')]
    public function testReportDropsTheWholeInvalidDeclaration(string $property, string $value): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        self::assertSame([], (new Declarations($diagnostics))->expand($property, $value));
        self::assertCount(1, $diagnostics->all());
        self::assertSame('css.value', $diagnostics->all()[0]->code);
    }

    #[DataProvider('invalidDeclarations')]
    public function testRejectRemainsExplicit(string $property, string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Declarations(new Diagnostics()))->expand($property, $value);
    }

    public function testGlobalKeywordExpandsAtomically(): void
    {
        $declarations = new Declarations(new Diagnostics());
        self::assertSame(['padding-top' => 'inherit', 'padding-right' => 'inherit', 'padding-bottom' => 'inherit', 'padding-left' => 'inherit'], $declarations->expand('padding', 'inherit'));
        $border = $declarations->expand('border', 'unset');
        self::assertCount(12, $border);
        self::assertSame(['unset'], array_values(array_unique($border)));
    }

    public function testShorthandSupportsDecimalRgbAndDoesNotRoundOrClipUntilColorComputation(): void
    {
        $declarations = new Declarations(new Diagnostics());
        $expanded = $declarations->expand('border-left', 'rgb(-2.5%, 150%, 12.5%) dashed 2px');
        self::assertSame('rgb(-2.5%, 150%, 12.5%)', $expanded['border-left-color']);
        self::assertSame('dashed', $expanded['border-left-style']);
        self::assertSame('2px', $expanded['border-left-width']);
    }

    public function testRelativeValuesAreNotComputedAgainstAnInventedParent(): void
    {
        $declarations = new Declarations(new Diagnostics());
        self::assertSame(['font-size' => '1000000em'], $declarations->expand('font-size', '1000000em'));
        self::assertSame(['vertical-align' => 'middle'], $declarations->expand('vertical-align', 'middle'));
    }
}
