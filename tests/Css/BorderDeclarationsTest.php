<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\BorderComputer;
use PDF\HtmlToPdf\Css\BorderDeclarations;
use PDF\HtmlToPdf\Css\StyleComputer;
use PDF\HtmlToPdf\Css\StyleState;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Style\BorderStyle;
use PDF\HtmlToPdf\Style\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BorderDeclarationsTest extends TestCase
{
    public function testOneToFourLonghandValuesFollowClockwiseOrder(): void
    {
        $parser = new BorderDeclarations();
        self::assertSame(['border-top-width' => 'thin', 'border-right-width' => 'medium', 'border-bottom-width' => 'thick', 'border-left-width' => '0'], $parser->expand('border-width', 'thin medium thick 0'));
        self::assertSame(['border-top-style' => 'solid', 'border-right-style' => 'dashed', 'border-bottom-style' => 'double', 'border-left-style' => 'dashed'], $parser->expand('border-style', 'solid dashed double'));
        self::assertSame(['border-top-color' => 'red', 'border-right-color' => 'rgb(0, 0, 255)', 'border-bottom-color' => 'red', 'border-left-color' => 'rgb(0, 0, 255)'], $parser->expand('border-color', 'red rgb(0, 0, 255)'));
    }

    public function testShorthandOrderOmissionsAndGlobalReset(): void
    {
        $parser = new BorderDeclarations();
        self::assertSame($parser->expand('border', '2pt solid red'), $parser->expand('border', 'red solid 2pt'));
        self::assertSame(['border-left-width' => 'medium', 'border-left-style' => 'solid', 'border-left-color' => 'currentcolor'], $parser->expand('border-left', 'solid'));
        self::assertSame(['border-right-width' => 'initial', 'border-right-style' => 'initial', 'border-right-color' => 'initial'], $parser->expand('border-right', 'initial'));
        self::assertCount(12, $parser->expand('border', 'inherit'));
        self::assertNull($parser->expand('background-color', 'red'));
    }

    #[DataProvider('invalidValues')]
    public function testMalformedShorthandsCannotYieldPartialAssignments(string $property, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BorderDeclarations())->expand($property, $value);
    }

    public static function invalidValues(): iterable
    {
        yield ['border', 'red blue solid'];
        yield ['border', '2pt 3pt solid'];
        yield ['border', 'solid none'];
        yield ['border', 'inherit solid'];
        yield ['border', '-1pt solid red'];
        yield ['border', '10% solid'];
        yield ['border-color', 'red initial'];
        yield ['border-left-width', 'thin thick'];
        yield ['border', 'rgb(0,0,0)red solid'];
        yield ['border', 'rgb(0,0,0) solid trailing'];
        yield ['border-style', 'solid dashed double ridge groove'];
        yield ['border', "1pt\vsolid red"];
        yield ['border', "\v1pt solid red"];
        yield ['border-style', "solid\v"];
    }

    public function testMediumInitialAndZeroWidthStyleSurviveComputationAndInheritance(): void
    {
        $computer = new StyleComputer(new Diagnostics());
        $parser = new BorderDeclarations();
        $parent = $computer->compute('div', $parser->expand('border', '0 solid red'), new StyleState());
        self::assertSame(BorderStyle::Solid, $parent->style->borderEdges()->top->style);
        $child = $computer->compute('div', ['border-top-style' => 'inherit', 'border-top-width' => '2pt'], $parent);
        self::assertSame(2.0, $child->style->borderEdges()->top->effectiveWidth());
        $defaultWidth = $computer->compute('div', ['border-top-style' => 'solid'], new StyleState());
        self::assertSame(2.25, $defaultWidth->style->borderEdges()->top->effectiveWidth());
        $none = $computer->compute('div', ['border-top-style' => 'none', 'border-top-width' => '12pt'], new StyleState());
        $inheritedWidth = $computer->compute('div', ['border-top-style' => 'solid', 'border-top-width' => 'inherit'], $none);
        self::assertSame(0.0, $inheritedWidth->style->borderEdges()->top->effectiveWidth());
    }

    public function testCurrentColorIsResolvedBeforeInheritanceAndTransparentKeepsWidth(): void
    {
        $computer = new StyleComputer(new Diagnostics());
        $parent = $computer->compute('div', ['color' => 'red', 'border-top-style' => 'solid'], new StyleState());
        $child = $computer->compute('div', ['color' => 'blue', 'border-top-color' => 'inherit', 'border-top-style' => 'inherit', 'border-right-color' => 'transparent', 'border-right-style' => 'solid'], $parent);
        self::assertEquals(new Color(1, 0, 0), $child->style->borderEdges()->top->color);
        self::assertNull($child->style->borderEdges()->right->color);
        self::assertSame(2.25, $child->style->borderEdges()->right->effectiveWidth());
    }

    public function testRelativeWidthsUseComputedElementFontAndKeywordsStayConstant(): void
    {
        $result = (new BorderComputer())->compute(['border-top-width' => '2em', 'border-top-style' => 'solid', 'border-right-width' => 'thick', 'border-right-style' => 'solid'], 15, 12, new Color());
        self::assertSame(30.0, $result->top->effectiveWidth());
        self::assertSame(3.75, $result->right->effectiveWidth());
    }

    public function testLegacyUniformComputedStyleInputRemainsSupported(): void
    {
        $computer = new StyleComputer(new Diagnostics());
        $parent = $computer->compute('div', ['border-width' => '2pt', 'border-style' => 'solid', 'border-color' => 'red', 'border-left-width' => '4pt'], new StyleState());
        self::assertSame(2.0, $parent->style->borderWidth);
        self::assertSame(4.0, $parent->style->borderEdges()->left->effectiveWidth());
        self::assertEquals(new Color(1, 0, 0), $parent->style->borderEdges()->right->color);
        $child = $computer->compute('div', ['border-width' => 'inherit', 'border-style' => 'inherit', 'border-color' => 'inherit'], $parent);
        self::assertEquals($parent->style->borderEdges(), $child->style->borderEdges());
    }
}
