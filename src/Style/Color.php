<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

final readonly class Color
{
    public function __construct(public float $red = 0, public float $green = 0, public float $blue = 0)
    {
        foreach ([$red,$green,$blue] as $value) {
            if (!is_finite($value) || $value < 0 || $value > 1) {
                throw new \InvalidArgumentException('RGB components must be between zero and one.');
            }
        }
    }
}
