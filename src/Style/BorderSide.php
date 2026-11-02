<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** Width and style stay independent, including zero-width and transparent borders. */
final readonly class BorderSide
{
    public function __construct(public float $width = 2.25, public BorderStyle $style = BorderStyle::None, public ?Color $color = new Color())
    {
        if (!is_finite($width) || $width < 0 || $width > 1_000_000) {
            throw new \InvalidArgumentException('Border width must be finite, non-negative and bounded.');
        }
    }

    public function effectiveWidth(): float
    {
        return $this->style === BorderStyle::None ? 0 : $this->width;
    }
}
