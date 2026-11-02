<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\ComputedStyle;

/** Preserve a unitless line-height through inheritance until child font sizing. */
final readonly class StyleState
{
    public function __construct(public ComputedStyle $style = new ComputedStyle(), public ?float $lineHeightFactor = 4 / 3) {}
}
