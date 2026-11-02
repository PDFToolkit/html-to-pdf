<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\CurveTo;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTo;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\StrokePath;
use PDF\Syntax\Object\NumericType;

/** Fixed vector appearance for the explicitly requested static form snapshot. */
final readonly class RadioButtonPainter
{
    /** @return list<Operator> */
    public function operators(RadioButton $radio, float $pageHeight): array
    {
        $x = $radio->x + $radio->width / 2;
        $y = $pageHeight - $radio->y - $radio->height / 2;
        $radius = max(0, min($radio->width, $radio->height) / 2 - 0.5);
        $shade = $radio->disabled ? 0.65 : 0.35;
        $path = $this->circle($x, $y, $radius);
        $operators = [new SetNonstrokingRGBColor(new NumericType(1), new NumericType(1), new NumericType(1)), ...$path, new FillPathNonzero(),
            new SetStrokingRGBColor(new NumericType($shade), new NumericType($shade), new NumericType($shade)), new SetLineWidth(new NumericType(0.75)), ...$path, new StrokePath()];
        if ($radio->checked) {
            array_push($operators, new SetNonstrokingRGBColor(new NumericType($shade), new NumericType($shade), new NumericType($shade)), ...$this->circle($x, $y, $radius * 0.5));
            $operators[] = new FillPathNonzero();
        }
        return $operators;
    }

    /** @return list<Operator> */
    private function circle(float $x, float $y, float $radius): array
    {
        $k = $radius * 0.5522847498307936;
        $n = static fn(float $value): NumericType => new NumericType($value);
        return [new MoveTo($n($x + $radius), $n($y)),
            new CurveTo($n($x + $radius), $n($y + $k), $n($x + $k), $n($y + $radius), $n($x), $n($y + $radius)),
            new CurveTo($n($x - $k), $n($y + $radius), $n($x - $radius), $n($y + $k), $n($x - $radius), $n($y)),
            new CurveTo($n($x - $radius), $n($y - $k), $n($x - $k), $n($y - $radius), $n($x), $n($y - $radius)),
            new CurveTo($n($x + $k), $n($y - $radius), $n($x + $radius), $n($y - $k), $n($x + $radius), $n($y))];
    }
}
