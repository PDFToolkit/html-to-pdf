<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\Fonts\OpenType\ShapedText;
use PDF\Fonts\TrueType\UnicodeText;
use PDFToolkit\OpenType\Sfnt\ReadLimits;
use PDFToolkit\OpenType\Sfnt\SfntFont;

/** Face-owned lazy preparation: unused variants never allocate layout tables. */
final class LayoutPreparation
{
    private ?PreparedLayout $prepared = null;

    public function __construct(private readonly SfntFont $font, private readonly ReadLimits $limits) {}

    public function create(UnicodeText $text): ShapedText
    {
        $this->prepared ??= new PreparedLayout($this->font, $this->limits);

        return $this->prepared->create($text);
    }
}
