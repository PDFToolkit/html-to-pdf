<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\BorderSide;
use PDF\HtmlToPdf\Style\Borders;
use PDF\HtmlToPdf\Style\BorderStyle;
use PDF\HtmlToPdf\Style\Color;

/** Converts resolved longhands; cascade/global keywords are handled by StyleComputer. */
final readonly class BorderComputer
{
    /** @return array<string,string> */
    public function defaults(): array
    {
        $result = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $result['border-' . $side . '-width'] = 'medium';
            $result['border-' . $side . '-style'] = 'none';
            $result['border-' . $side . '-color'] = 'currentcolor';
        }
        return $result;
    }

    /** @param array<string,string> $raw */
    public function compute(array $raw, float $fontSize, float $rootSize, Color $current): Borders
    {
        $values = new Values();
        $sides = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $prefix = 'border-' . $side . '-';
            $width = strtolower(trim($raw[$prefix . 'width'] ?? 'medium'));
            $width = match ($width) {
                'thin' => 0.75,
                'medium' => 2.25,
                'thick' => 3.75,
                default => $values->points($width, $fontSize, $rootSize),
            };
            $style = BorderStyle::tryFrom(strtolower(trim($raw[$prefix . 'style'] ?? 'none')))
                ?? throw new \InvalidArgumentException('Unsupported border style.');
            $color = $values->color($raw[$prefix . 'color'] ?? 'currentcolor', $current);
            $sides[] = new BorderSide($width, $style, $color);
        }
        return new Borders(...$sides);
    }

    /** @return array<string,string> */
    public function serialize(Borders $borders): array
    {
        $result = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $border = $borders->{$side};
            $result['border-' . $side . '-width'] = $border->effectiveWidth() . 'pt';
            $result['border-' . $side . '-style'] = $border->style->value;
            $color = $border->color;
            $result['border-' . $side . '-color'] = (new ColorFormatter())->format($color);
        }
        return $result;
    }
}
