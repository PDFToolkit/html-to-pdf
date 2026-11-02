<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Css\ColorFormatter;
use PDF\HtmlToPdf\Css\Values;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ColorFormatterTest extends CompilerTestCase
{
    /** @return iterable<string,array{float}> */
    public static function tinyChannels(): iterable
    {
        yield 'small' => [1e-11];
        yield 'very small' => [1e-100];
        yield 'near normal limit' => [1e-300];
        yield 'subnormal' => [1e-320];
        yield 'smallest subnormal' => [5e-324];
    }

    #[DataProvider('tinyChannels')]
    public function testFormatterPreservesSmallFiniteChannelsWithoutExponentOrArbitraryRounding(float $channel): void
    {
        $text = (new ColorFormatter())->format(new Color($channel, .125, 1));
        self::assertDoesNotMatchRegularExpression('/[eE][+-]?[0-9]/', $text);
        self::assertLessThan(1100, strlen($text));
        $actual = (new Values())->color($text, new Color());
        self::assertNotNull($actual);
        self::assertGreaterThan(0, $actual->red);
        self::assertEqualsWithDelta(1.0, $actual->red / $channel, 1e-14);
        self::assertSame(.125, $actual->green);
        self::assertSame(1.0, $actual->blue);
    }

    public function testTransparentAndZeroChannelsUseValidCssWithoutChangingGlobalNumericSettings(): void
    {
        $before = ini_get('serialize_precision');
        $formatter = new ColorFormatter();
        self::assertSame('transparent', $formatter->format(null));
        self::assertSame('rgb(0%,0%,0%)', $formatter->format(new Color()));
        self::assertSame($before, ini_get('serialize_precision'));
    }

    public function testSmallRgbColorIsInheritedThroughTheCompleteCompilerWithoutDiagnostics(): void
    {
        $document = $this->compile('<div style="color:rgb(.000000001%,0%,0%)"><p id="x">Text</p></div>');
        self::assertEqualsWithDelta(1.0, $this->find($document->root, 'x')->style->color->red / 1e-11, 1e-14);
    }

    public function testSmallBorderColorIsInheritedAndCurrentColorUsesItsOriginalPrecision(): void
    {
        $document = $this->compile('<div style="color:rgb(.000000001%,0%,0%);border:1px solid currentcolor"><p id="x" style="border:inherit">Text</p></div>');
        $border = $this->find($document->root, 'x')->style->borderEdges();
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertNotNull($border->$side->color);
            self::assertEqualsWithDelta(1.0, $border->$side->color->red / 1e-11, 1e-14);
        }
    }
}
