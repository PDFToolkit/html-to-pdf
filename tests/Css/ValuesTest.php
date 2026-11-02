<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\Values;
use PDF\HtmlToPdf\Style\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValuesTest extends TestCase
{
    /** @return iterable<string,array{string,array{float,float,float}}> */
    public static function rgbColors(): iterable
    {
        yield 'integer clipping' => ['rgb(-20,260,0)', [0.0, 1.0, 0.0]];
        yield 'wide integer clipping' => ['rgb(10000000000,-10000000000,255)', [1.0, 0.0, 1.0]];
        yield 'signed integers' => ['rgb(+255,+0,-0)', [1.0, 0.0, 0.0]];
        yield 'percentage clipping' => ['rgb(-10%,120%,50%)', [0.0, 1.0, .5]];
        yield 'fractional percentages' => ['rgb(+12.5%,.25%,99.75%)', [.125, .0025, .9975]];
        yield 'case and whitespace' => ["RGB(\t10%,\n20%,\f30% )", [.1, .2, .3]];
        yield 'fractional percentage precision' => ['rgb(12.123456789%,0%,0%)', [.12123456789, 0.0, 0.0]];
    }

    /** @param array{float,float,float} $expected */
    #[DataProvider('rgbColors')]
    public function testCss1RgbClampsEachComponentWithoutParserHexSerialization(string $value, array $expected): void
    {
        $color = (new Values())->color($value, new Color());
        self::assertInstanceOf(Color::class, $color);
        self::assertEqualsWithDelta($expected, [$color->red, $color->green, $color->blue], 1e-12);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidColors(): iterable
    {
        foreach (['rgb(0..1,0,0)', 'rgb(1.2.3%,0%,0%)', 'rgb(10%,0,0)', 'rgb(0,0%,0)', 'rgb(0.5,0,0)', 'rgb(1.,0,0)', 'rgb(1e2,0,0)', 'rgb(1,2)', 'rgb(1,2,3,4)', 'rgb(1 2 3)', 'rgb (1,2,3)', 'rgba(1,2,3,1)', 'rgb(1px,2px,3px)', '#gg0000', '#12345', '#12345678'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidColors')]
    public function testMalformedAndOutOfProfileColorFormsAreRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Values())->color($value, new Color());
    }

    public function testHugeNumericTextIsBoundedByFiniteValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Values())->color('rgb(' . str_repeat('9', 400) . ',0,0)', new Color());
    }
}
