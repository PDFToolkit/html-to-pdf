<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\Fonts\OpenType\ShapedText;
use PDF\HtmlToPdf\Style\TextSpacing;

/** One shaped source run, with the exact positions later consumed by the PDF painter. */
final readonly class FontRun
{
    public function __construct(
        public FontFace $font,
        public ShapedText $text,
        public float $size,
        public float $width,
        public FontMetrics $metrics,
        public TextSpacing $spacing = new TextSpacing(),
    ) {
        if ($text->font !== $font->program->font || !is_finite($size) || $size <= 0 || !is_finite($width) || $width < 0) {
            throw new \InvalidArgumentException('A font run must retain its font identity and finite metrics.');
        }
    }
}
