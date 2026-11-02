<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

/** Distances in PDF points; descent is the positive distance below the baseline. */
final readonly class FontMetrics
{
    public function __construct(public float $ascent, public float $descent, public float $lineGap)
    {
        if (!is_finite($ascent) || !is_finite($descent) || !is_finite($lineGap) || min($ascent, $descent, $lineGap) < 0) {
            throw new \InvalidArgumentException('Font metrics must be finite non-negative distances.');
        }
    }

    public function height(): float
    {
        return $this->ascent + $this->descent + $this->lineGap;
    }
}
