<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Drawing;

use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\TextSpacing;

/**
 * x and baseline y are in points from the page's top-left.
 *
 * With $capitals the glyphs are those of the uppercase mapping of the text, for
 * synthesized small capitals; the text itself stays the source for extraction.
 */
final readonly class Text implements Command
{
    public function __construct(public FontFace $font, public string $text, public float $size, public float $x, public float $y, public Color $color, public ?string $semanticId, public ?string $uri = null, public TextSpacing $spacing = new TextSpacing(), public bool $capitals = false) {}

    /** Complete copy at another position: every shaping input travels with the command. */
    public function at(float $x, float $y): self
    {
        return new self(...array_replace(get_object_vars($this), ['x' => $x, 'y' => $y]));
    }

    /** Complete artifact copy: the same shaped appearance without structure or link. */
    public function detached(): self
    {
        return new self(...array_replace(get_object_vars($this), ['semanticId' => null, 'uri' => null]));
    }
}
