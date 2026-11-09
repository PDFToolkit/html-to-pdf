<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** Signed first-line offset, with percentages retained until the containing width is known. */
final readonly class TextIndent
{
    public function __construct(public float $value = 0, public bool $percentage = false)
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Text indentation must be finite.');
        }
    }

    public function resolve(float $basis): float
    {
        if (!is_finite($basis) || $basis < 0) {
            throw new \InvalidArgumentException('The text indentation basis must be finite and non-negative.');
        }
        $resolved = $this->percentage ? $basis * ($this->value / 100) : $this->value;
        if (!is_finite($resolved)) {
            throw new \InvalidArgumentException('Resolved text indentation must be finite.');
        }

        return $resolved;
    }
}
