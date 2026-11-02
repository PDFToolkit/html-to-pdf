<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

final readonly class Length
{
    public function __construct(public float $value, public bool $percentage = false)
    {
        if (!is_finite($value) || $value < 0) {
            throw new \InvalidArgumentException('Lengths must be finite and non-negative.');
        }
    }
    public function resolve(float $basis): float
    {
        return $this->percentage ? $basis * $this->value / 100 : $this->value;
    }
}
