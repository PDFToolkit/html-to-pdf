<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;

/** An enclosing rectangle in bottom-left PDF page coordinates. */
final readonly class Bounds
{
    public function __construct(public float $left, public float $bottom, public float $right, public float $top)
    {
        foreach ([$left, $bottom, $right, $top] as $number) {
            if (!is_finite($number)) {
                throw new \InvalidArgumentException('Drawing bounds must be finite.');
            }
        }
        if ($left > $right || $bottom > $top) {
            throw new \InvalidArgumentException('Drawing bounds must be ordered.');
        }
    }

    public function union(self $other): self
    {
        return new self(min($this->left, $other->left), min($this->bottom, $other->bottom), max($this->right, $other->right), max($this->top, $other->top));
    }

    public function pdf(): Rectangle
    {
        return new Rectangle(new NumericType($this->left), new NumericType($this->bottom), new NumericType($this->right), new NumericType($this->top));
    }
}
