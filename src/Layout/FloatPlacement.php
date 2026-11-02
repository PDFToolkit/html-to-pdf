<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

/** The margin rectangle of one float in its formatting context. */
final readonly class FloatPlacement
{
    public function __construct(public string $side, public float $x, public float $top, public float $width, public float $height) {}

    public function bottom(): float
    {
        return $this->top + $this->height;
    }
}
