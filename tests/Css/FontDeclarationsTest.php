<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\Declarations;
use PDF\HtmlToPdf\Css\FontFamilyParser;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class FontDeclarationsTest extends CompilerTestCase
{
    public function testW3cShorthandComputesRootAndInheritedRelativeDimensions(): void
    {
        $document = $this->compile('<html><body><div id="outer"><p id="inner">Text</p></div></body></html>', 'html{font:10px/1 Verdana,sans-serif}#outer{width:48em;padding:.5em}#inner{font-size:2em;line-height:1.9;width:10.638%}');
        $body = $document->root->style;
        self::assertSame(7.5, $body->fontSize);
        self::assertSame(7.5, $body->lineHeight);
        self::assertSame('"Verdana", sans-serif', $body->fontFamily->css());
        $outer = $this->find($document->root, 'outer')->style;
        self::assertSame(360.0, $outer->width?->resolve(1000));
        self::assertSame(3.75, $outer->padding->left);
        $inner = $this->find($document->root, 'inner')->style;
        self::assertSame(15.0, $inner->fontSize);
        self::assertSame(28.5, $inner->lineHeight);
        self::assertEqualsWithDelta(37.49895, $inner->width?->resolve(352.5), 0.000001);
        self::assertEquals($body->fontFamily, $inner->fontFamily);
    }

    public function testUnitlessLineHeightRemainsAFactorInDescendants(): void
    {
        $document = $this->compile('<div id="a"><p id="b">B</p></div>', '#a{font:10px/1 sans-serif}#b{font-size:2em}');
        self::assertSame(15.0, $this->find($document->root, 'b')->style->lineHeight);
    }

    public function testShorthandResetsAllComponentsAndHonorsOrderedLonghands(): void
    {
        $declarations = (new Declarations(new Diagnostics()))->expand('font', '10px sans-serif');
        self::assertSame(['font-style' => 'normal', 'font-variant' => 'normal', 'font-weight' => 'normal', 'font-size' => '10px', 'line-height' => 'normal', 'font-family' => 'sans-serif'], $declarations);
        $document = $this->compile('<p id="a">A</p><p id="b">B</p>', 'p{font-style:italic;font-weight:bold;line-height:3;font-family:serif}#a{font:10px sans-serif}#b{font:10px sans-serif;font-weight:bold}');
        $a = $this->find($document->root, 'a')->style;
        self::assertFalse($a->italic);
        self::assertSame(400, $a->fontWeight);
        self::assertSame(10.0, $a->lineHeight);
        self::assertSame('sans-serif', $a->fontFamily->css());
        self::assertSame(700, $this->find($document->root, 'b')->style->fontWeight);
    }

    public function testShorthandIsAtomicForAnUnsupportedVariant(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="a">A</p>', 'p{font-size:20pt;font-weight:bold;font-family:serif;font:italic small-caps 10px sans-serif}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        $style = $this->find($document->root, 'a')->style;
        self::assertSame(20.0, $style->fontSize);
        self::assertSame(700, $style->fontWeight);
        self::assertFalse($style->italic);
        self::assertSame('serif', $style->fontFamily->css());
        self::assertCount(1, $diagnostics->all());
    }

    public function testWideKeywordsResetOrInheritFamiliesAndFontFactor(): void
    {
        $document = $this->compile('<div><p id="inherit">A</p><p id="initial">B</p><p id="unset">C</p></div>', 'div{font:italic bold 10px/1.9 "Example Font",serif}#inherit{font:inherit;font-size:2em}#initial{font:initial}#unset{font:unset}');
        $inherited = $this->find($document->root, 'inherit')->style;
        self::assertTrue($inherited->italic);
        self::assertSame(700, $inherited->fontWeight);
        self::assertSame(28.5, $inherited->lineHeight);
        self::assertSame('"Example Font", serif', $inherited->fontFamily->css());
        $initial = $this->find($document->root, 'initial')->style;
        self::assertSame([], $initial->fontFamily->names);
        self::assertSame(12.0, $initial->fontSize);
        self::assertSame(16.0, $initial->lineHeight);
        self::assertSame(14.25, $this->find($document->root, 'unset')->style->lineHeight);
    }

    public function testFontFamilyGrammarDistinguishesNamesFromGenericKeywords(): void
    {
        $families = (new FontFamilyParser())->parse('"sans-serif", Example Font, \\56 erdana, SANS-SERIF');
        self::assertSame(['sans-serif', 'Example Font', 'Verdana', 'sans-serif'], array_map(static fn($family) => $family->name, $families->names));
        self::assertSame([false, false, false, true], array_map(static fn($family) => $family->generic, $families->names));
        self::assertEquals($families, (new FontFamilyParser())->parse($families->css()));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidShorthands(): iterable
    {
        foreach (['10px', 'italic bold bold 10px serif', 'normal normal normal normal 10px serif', '10px / serif', '10px//1 serif', '10px/0 serif', '10px/1 serif,', 'italic 0px serif', 'small-caps 10px serif', 'italic oblique 10px serif', 'oblique oblique 10px serif', 'caption', '10px serif,,sans-serif', '10px "unterminated'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidShorthands')]
    public function testUnsupportedOrInvalidShorthandIsRejected(string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Declarations(new Diagnostics()))->expand('font', $value);
    }

    public function testFloatAndClearAreNotInheritedUnlessRequested(): void
    {
        $document = $this->compile('<div id="a"><div id="b"><div id="c">C</div></div><div id="d">D</div></div>', '#a{float:left;clear:both}#b{float:inherit;clear:inherit}#c{float:initial;clear:unset}');
        self::assertSame('left', $this->find($document->root, 'b')->style->float);
        self::assertSame('both', $this->find($document->root, 'b')->style->clear);
        foreach (['c', 'd'] as $id) {
            self::assertSame('none', $this->find($document->root, $id)->style->float);
            self::assertSame('none', $this->find($document->root, $id)->style->clear);
        }
    }

    public function testInvalidFloatDoesNotEraseEarlierDeclarationInReportMode(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<div id="a">A</div>', '#a{float:right;float:top;clear:left;clear:all}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertSame('right', $this->find($document->root, 'a')->style->float);
        self::assertSame('left', $this->find($document->root, 'a')->style->clear);
        self::assertCount(2, $diagnostics->all());
    }
}
