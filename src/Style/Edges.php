<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

final readonly class Edges
{
    public function __construct(public float $top = 0, public float $right = 0, public float $bottom = 0, public float $left = 0)
    {
        foreach ([$top,$right,$bottom,$left] as $value) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Box edges must be finite.');
            }
        }
    }
    public function horizontal(): float
    {
        return $this->left + $this->right;
    }
    public function vertical(): float
    {
        return $this->top + $this->bottom;
    }
}
