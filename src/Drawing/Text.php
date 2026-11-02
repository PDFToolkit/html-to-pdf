<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Drawing;

use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Style\Color;

/** x and baseline y are in points from the page's top-left. */
final readonly class Text implements Command
{
    public function __construct(public FontFace $font, public string $text, public float $size, public float $x, public float $y, public Color $color, public ?string $semanticId, public ?string $uri = null) {}
}
