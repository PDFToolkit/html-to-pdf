<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

final readonly class Borders
{
    public function __construct(public BorderSide $top = new BorderSide(), public BorderSide $right = new BorderSide(), public BorderSide $bottom = new BorderSide(), public BorderSide $left = new BorderSide()) {}

    public static function uniform(BorderSide $side): self
    {
        return new self($side, $side, $side, $side);
    }

    public function widths(): Edges
    {
        return new Edges($this->top->effectiveWidth(), $this->right->effectiveWidth(), $this->bottom->effectiveWidth(), $this->left->effectiveWidth());
    }

    public function hasWidth(): bool
    {
        $widths = $this->widths();
        return $widths->horizontal() + $widths->vertical() > 0;
    }
}
