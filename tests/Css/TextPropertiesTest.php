<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\Declarations;
use PDF\HtmlToPdf\Css\StyleComputer;
use PDF\HtmlToPdf\Css\StyleState;
use PDF\HtmlToPdf\Css\TextValues;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\TextIndent;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class TextPropertiesTest extends CompilerTestCase
{
    /** @return iterable<string,array{string,float}> */
    public static function signedLengths(): iterable
    {
        yield 'positive points' => ['+3pt', 3.0];
        yield 'negative pixels' => ['-2px', -1.5];
        yield 'picas' => ['.25pc', 3.0];
        yield 'millimeters' => ['-25.4mm', -72.0];
        yield 'centimeters' => ['2.54cm', 72.0];
        yield 'inches' => ['-1in', -72.0];
        yield 'current font' => ['-.25em', -5.0];
        yield 'root font extension' => ['.5rem', 8.0];
        yield 'unitless zero' => ['0', 0.0];
        yield 'negative zero' => ['-0', 0.0];
        yield 'case and whitespace' => ["\t +2PX\n", 1.5];
    }

    #[DataProvider('signedLengths')]
    public function testSignedTextLengthsComputeUsingTheElementFont(string $value, float $expected): void
    {
        $computer = new StyleComputer(new Diagnostics());
        $style = $computer->compute('p', ['font-size' => '20pt', 'letter-spacing' => $value, 'word-spacing' => $value, 'text-indent' => $value], new StyleState(), 16)->style;
        self::assertEqualsWithDelta($expected, $style->textSpacing->letterSpacing, 1e-12);
        self::assertEqualsWithDelta($expected, $style->textSpacing->wordSpacing, 1e-12);
        self::assertEqualsWithDelta($expected, $style->textIndent->resolve(400), 1e-12);
        self::assertFalse($style->textIndent->percentage);
    }

    public function testInheritedEmSpacingAndIndentRemainAbsoluteWhenFontSizeChanges(): void
    {
        $document = $this->compile('<div id="parent"><p id="child">Text</p></div>', '#parent{font-size:20pt;letter-spacing:-.1em;word-spacing:.2em;text-indent:2em}#child{font-size:40pt}');
        $parent = $this->find($document->root, 'parent')->style;
        $child = $this->find($document->root, 'child')->style;
        self::assertSame(-2.0, $child->textSpacing->letterSpacing);
        self::assertSame(4.0, $child->textSpacing->wordSpacing);
        self::assertSame(40.0, $child->textIndent->resolve(400));
        self::assertEquals($parent->textSpacing, $child->textSpacing);
        self::assertSame($parent->textIndent, $child->textIndent);
    }

    public function testInheritedPercentageIndentResolvesAgainstEachContainingWidth(): void
    {
        $document = $this->compile('<div id="parent"><p id="child">Text</p></div>', '#parent{text-indent:-12.5%;width:200pt}#child{width:100pt;font-size:24pt}');
        $parent = $this->find($document->root, 'parent')->style->textIndent;
        $child = $this->find($document->root, 'child')->style->textIndent;
        self::assertSame($parent, $child);
        self::assertTrue($child->percentage);
        self::assertSame(-50.0, $parent->resolve(400));
        self::assertSame(-25.0, $child->resolve(200));
    }

    public function testNormalAndZeroAreDistinctAndCssWideKeywordsKeepTheirMeaning(): void
    {
        $document = $this->compile('<div><p id="inherit">A</p><p id="unset">B</p><p id="initial">C</p><p id="normal">D</p><p id="zero">E</p></div>', 'div{letter-spacing:3pt;word-spacing:-2pt;text-indent:10%}#inherit{letter-spacing:inherit;word-spacing:inherit;text-indent:inherit}#unset{letter-spacing:unset;word-spacing:unset;text-indent:unset}#initial{letter-spacing:initial;word-spacing:initial;text-indent:initial}#normal{letter-spacing:normal;word-spacing:normal}#zero{letter-spacing:0;word-spacing:0;text-indent:0}');
        foreach (['inherit', 'unset'] as $id) {
            $style = $this->find($document->root, $id)->style;
            self::assertEquals(new TextSpacing(3, -2), $style->textSpacing);
            self::assertEquals(new TextIndent(10, true), $style->textIndent);
        }
        $initial = $this->find($document->root, 'initial')->style;
        self::assertEquals(new TextSpacing(), $initial->textSpacing);
        self::assertEquals(new TextIndent(), $initial->textIndent);
        self::assertEquals(new TextSpacing(), $this->find($document->root, 'normal')->style->textSpacing);
        $zero = $this->find($document->root, 'zero')->style;
        self::assertEquals(new TextSpacing(0, 0), $zero->textSpacing);
        self::assertNotSame($initial->textSpacing->cacheKey(), $zero->textSpacing->cacheKey());
        self::assertSame((new TextSpacing(0, 0))->cacheKey(), (new TextSpacing(-0.0, -0.0))->cacheKey());
    }

    public function testInvalidImportantDeclarationsCannotEraseEarlierValidValues(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="subject" style="letter-spacing:10%!important;word-spacing:1..2pt!important;text-indent:normal!important">Text</p>', '#subject{letter-spacing:-2pt;word-spacing:3pt;text-indent:-5%}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        $style = $this->find($document->root, 'subject')->style;
        self::assertEquals(new TextSpacing(-2, 3), $style->textSpacing);
        self::assertEquals(new TextIndent(-5, true), $style->textIndent);
        self::assertCount(3, $diagnostics->all());
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidValues(): iterable
    {
        foreach (['letter-spacing', 'word-spacing', 'text-indent'] as $property) {
            foreach (['auto', '1', '1e2pt', '1..2pt', 'calc(1pt + 2pt)', '1ex', '2pt 3pt', 'inherit 1pt', '1000001pt', '-1000001pt', '', 'NaN', 'INF'] as $value) {
                yield $property . ':' . $value => [$property, $value];
            }
        }
        yield 'percentage letter spacing' => ['letter-spacing', '5%'];
        yield 'percentage word spacing' => ['word-spacing', '-5%'];
        yield 'no normal indent' => ['text-indent', 'normal'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidSpecifiedTextValuesAreRejectedBeforeCascade(string $property, string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Declarations(new Diagnostics()))->expand($property, $value);
    }

    public function testInheritanceDoesNotReparseComputedLengthsAtSpecifiedTokenLimits(): void
    {
        $computer = new StyleComputer(new Diagnostics());
        $parent = $computer->compute('div', ['font-size' => '1000pt', 'word-spacing' => '1000000em', 'letter-spacing' => '-1000000em', 'text-indent' => '1000000em'], new StyleState());
        $child = $computer->compute('p', ['font-size' => '1pt', 'word-spacing' => 'inherit', 'text-indent' => 'unset'], $parent);
        self::assertEquals(new TextSpacing(-1_000_000_000, 1_000_000_000), $child->style->textSpacing);
        self::assertEquals(new TextIndent(1_000_000_000), $child->style->textIndent);
    }

    public function testSmallComputedLengthsSurviveInheritanceAndCssSerialization(): void
    {
        $number = -0.00000012345678912345678;
        $values = new TextValues();
        $spacing = $values->serializeSpacing($number);
        $indent = $values->serializeIndent(new TextIndent($number, true));
        self::assertStringNotContainsString('e', $spacing);
        self::assertSame($number, $values->spacing($spacing, 12, 12));
        self::assertEquals(new TextIndent($number, true), $values->indent($indent, 12, 12));
        $parent = new StyleState(new ComputedStyle(textSpacing: new TextSpacing($number, $number), textIndent: new TextIndent($number)));
        $child = (new StyleComputer(new Diagnostics()))->compute('p', [], $parent)->style;
        self::assertSame($number, $child->textSpacing->letterSpacing);
        self::assertSame($parent->style->textIndent, $child->textIndent);
    }

    /** @return iterable<string,array{float}> */
    public static function nonFiniteValues(): iterable
    {
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('nonFiniteValues')]
    public function testSpacingObjectsRejectNonFiniteValues(float $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextSpacing(letterSpacing: $value);
    }

    #[DataProvider('nonFiniteValues')]
    public function testWordSpacingObjectsRejectNonFiniteValues(float $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextSpacing(wordSpacing: $value);
    }

    #[DataProvider('nonFiniteValues')]
    public function testIndentObjectsRejectNonFiniteValues(float $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextIndent($value);
    }

    public function testIndentRejectsOverflowingResolvedGeometry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TextIndent(PHP_FLOAT_MAX, true))->resolve(PHP_FLOAT_MAX);
    }
}
