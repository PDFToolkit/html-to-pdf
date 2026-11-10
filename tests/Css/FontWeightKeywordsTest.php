<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\FontWeights;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/** CSS1 5.2.5 values, with bolder and lighter computed by the chart of CSS 2.1 15.6. */
final class FontWeightKeywordsTest extends CompilerTestCase
{
    /** Inherited value => [bolder, lighter], exactly as printed in CSS 2.1 15.6. */
    private const array CHART = [100 => [400, 100], 200 => [400, 100], 300 => [400, 100], 400 => [700, 100], 500 => [700, 100], 600 => [900, 400], 700 => [900, 400], 800 => [900, 700], 900 => [900, 700]];

    /** @return iterable<string,array{int,int,int}> */
    public static function chart(): iterable
    {
        foreach (self::CHART as $inherited => [$bolder, $lighter]) {
            yield (string) $inherited => [$inherited, $bolder, $lighter];
        }
    }

    #[DataProvider('chart')]
    public function testRelativeKeywordsFollowTheChart(int $inherited, int $bolder, int $lighter): void
    {
        $document = $this->compile('<div id="parent"><span id="bolder">A</span><span id="lighter">B</span><span id="same">C</span></div>', '#parent{font-weight:' . $inherited . '}#bolder{font-weight:bolder}#lighter{font-weight:LIGHTER}');
        self::assertSame($inherited, $this->find($document->root, 'same')->style->fontWeight);
        self::assertSame($bolder, $this->find($document->root, 'bolder')->style->fontWeight);
        self::assertSame($lighter, $this->find($document->root, 'lighter')->style->fontWeight);
    }

    public function testKeywordsAndNumbersNameTheSameScale(): void
    {
        $document = $this->compile('<p id="normal">A</p><p id="bold">B</p><p id="number">C</p><div id="parent"><p id="initial">D</p></div>', '#normal{font-weight:normal}#bold{font-weight:Bold}#number{font-weight:300}#parent{font-weight:900}#initial{font-weight:initial}');
        self::assertSame(400, $this->find($document->root, 'normal')->style->fontWeight);
        self::assertSame(700, $this->find($document->root, 'bold')->style->fontWeight);
        self::assertSame(300, $this->find($document->root, 'number')->style->fontWeight);
        self::assertSame(400, $this->find($document->root, 'initial')->style->fontWeight);
    }

    public function testChildrenInheritTheResultingWeightNotTheKeyword(): void
    {
        $document = $this->compile(
            '<div id="a"><div id="b"><p id="c">C</p><p id="d">D</p><p id="e">E</p></div></div>',
            '#a{font-weight:300}#b{font-weight:bolder}#d{font-weight:bolder}#e{font-weight:lighter}',
        );
        self::assertSame(400, $this->find($document->root, 'b')->style->fontWeight);
        self::assertSame(400, $this->find($document->root, 'c')->style->fontWeight);
        self::assertSame(700, $this->find($document->root, 'd')->style->fontWeight);
        self::assertSame(100, $this->find($document->root, 'e')->style->fontWeight);
    }

    public function testStrongAndBoldElementsAreBolderThanTheirParent(): void
    {
        $document = $this->compile(
            '<p><strong id="plain">A</strong></p><h1><b id="heading">B</b></h1><div id="thin"><strong id="from-thin">C</strong></div><h2 id="h2">D</h2><table><tr><th id="th">E</th></tr></table>',
            '#thin{font-weight:200}',
        );
        self::assertSame(700, $this->find($document->root, 'plain')->style->fontWeight);
        self::assertSame(900, $this->find($document->root, 'heading')->style->fontWeight);
        self::assertSame(400, $this->find($document->root, 'from-thin')->style->fontWeight);
        self::assertSame(700, $this->find($document->root, 'h2')->style->fontWeight);
        self::assertSame(700, $this->find($document->root, 'th')->style->fontWeight);
    }

    public function testFontShorthandAcceptsRelativeWeights(): void
    {
        $document = $this->compile('<div id="parent"><p id="bolder">A</p><p id="lighter">B</p><p id="reset">C</p></div>', '#parent{font-weight:600}#bolder{font:bolder 12px serif}#lighter{font:italic lighter 12px serif}#reset{font:12px serif}');
        self::assertSame(900, $this->find($document->root, 'bolder')->style->fontWeight);
        self::assertSame(400, $this->find($document->root, 'lighter')->style->fontWeight);
        self::assertTrue($this->find($document->root, 'lighter')->style->italic);
        self::assertSame(400, $this->find($document->root, 'reset')->style->fontWeight);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidWeights(): iterable
    {
        foreach (['boldest', 'heavy', '1000', '450', '0', '-400', '400.0', '4e2', 'bolder bolder', 'bold 700', 'medium'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidWeights')]
    public function testValuesOutsideTheCss1GrammarAreRejected(string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<p>A</p>', 'p{font-weight:' . $value . '}');
    }

    public function testInvalidWeightDoesNotEraseAnEarlierRelativeKeywordInReportMode(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<div id="parent"><p id="subject">A</p></div>', '#parent{font-weight:700}#subject{font-weight:lighter;font-weight:lightest;font:bolder bolder 12px serif}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertSame(400, $this->find($document->root, 'subject')->style->fontWeight);
        self::assertCount(2, $diagnostics->all());
    }

    public function testChartIsTotalForInheritedNumbersBetweenTheSteps(): void
    {
        $weights = new FontWeights();
        foreach ([1 => [400, 100], 349 => [400, 100], 350 => [700, 100], 549 => [700, 100], 550 => [900, 400], 650 => [900, 400], 749 => [900, 400], 750 => [900, 700], 1000 => [900, 700]] as $inherited => [$bolder, $lighter]) {
            self::assertSame($bolder, $weights->resolve('bolder', $inherited), 'bolder from ' . $inherited);
            self::assertSame($lighter, $weights->resolve('lighter', $inherited), 'lighter from ' . $inherited);
        }
        self::assertTrue($weights->isValue(' Bolder '));
        self::assertFalse($weights->isValue('000'));
        $this->expectException(\InvalidArgumentException::class);
        $weights->resolve('darker', 400);
    }
}
