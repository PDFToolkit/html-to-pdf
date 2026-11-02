<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;

/** Explicit variants, with no operating-system font lookup or synthetic bold/italic. */
final readonly class FontSet
{
    public function __construct(
        public FontFace $regular,
        public ?FontFace $bold = null,
        public ?FontFace $italic = null,
        public ?FontFace $boldItalic = null,
    ) {}

    public function face(FontStyle $style): FontFace
    {
        $face = match ($style) {
            FontStyle::Regular => $this->regular,
            FontStyle::Bold => $this->bold,
            FontStyle::Italic => $this->italic,
            FontStyle::BoldItalic => $this->boldItalic,
        };

        return $face ?? throw new UnsupportedFeature('Supply the ' . $style->value . ' font variant explicitly.');
    }
}
