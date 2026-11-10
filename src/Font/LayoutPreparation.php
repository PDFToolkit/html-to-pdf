<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\Fonts\OpenType\ShapedText;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDFToolkit\OpenType\Cmap\UnicodeCharacterMap;
use PDFToolkit\OpenType\Cmap\UnicodeCmapReader;
use PDFToolkit\OpenType\Sfnt\ReadLimits;
use PDFToolkit\OpenType\Sfnt\SfntFont;
use PDFToolkit\OpenType\Value\CodePoint;
use PDFToolkit\OpenType\Value\Tag;

/** Face-owned lazy preparation: unused variants never allocate layout tables. */
final class LayoutPreparation
{
    private ?PreparedLayout $prepared = null;
    private ?UnicodeCharacterMap $map = null;
    /** @var array<int, bool> Bounded memo: line breaking probes the same characters many times. */
    private array $coverage = [];

    public function __construct(private readonly SfntFont $font, private readonly ReadLimits $limits) {}

    public function create(UnicodeText $text, TextSpacing $spacing = new TextSpacing(), float $fontUnitsPerPoint = 1, bool $capitals = false): ShapedText
    {
        $this->prepared ??= new PreparedLayout($this->font, $this->limits, $this->map());

        return $this->prepared->create($text, $spacing, $fontUnitsPerPoint, $capitals);
    }

    /** Coverage reads the character map only: probing a fallback face prepares no shaping table. */
    public function covers(int $codePoint): bool
    {
        if (isset($this->coverage[$codePoint])) {
            return $this->coverage[$codePoint];
        }
        $covered = $this->map()->glyphFor(new CodePoint($codePoint))->value !== 0;
        if (count($this->coverage) < 65_536) {
            $this->coverage[$codePoint] = $covered;
        }

        return $covered;
    }

    private function map(): UnicodeCharacterMap
    {
        return $this->map ??= (new UnicodeCmapReader($this->limits))->read($this->font->table(new Tag('cmap')), $this->font->glyphCount());
    }
}
