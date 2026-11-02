<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Style\Edges;

final readonly class PageSettings
{
    public function __construct(public float $width = 595.275590551, public float $height = 841.88976378, public Edges $margin = new Edges(36, 36, 36, 36))
    {
        if (!is_finite($width) || !is_finite($height) || $width < 72 || $height < 72 || $width > 14400 || $height > 14400 || $margin->left < 0 || $margin->right < 0 || $margin->top < 0 || $margin->bottom < 0 || $width - $margin->horizontal() < 36 || $height - $margin->vertical() < 36) {
            throw new \InvalidArgumentException('Page geometry leaves no usable area.');
        }
    }
    public function contentWidth(): float
    {
        return $this->width - $this->margin->horizontal();
    }
    public function contentHeight(): float
    {
        return $this->height - $this->margin->vertical();
    }
}
