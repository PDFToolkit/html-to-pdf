<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Fonts\OpenType\GlyphText;
use PDF\Fonts\OpenType\TrueTypeFontEmbedding;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontRun;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Collect the document's exact glyph/text pairs before allocating PDF character codes. */
final class EmbeddedFont
{
    /** @var array<string, GlyphText> */
    private array $repertoire = [];
    private ?TrueTypeFontEmbedding $built = null;

    public function __construct(public readonly FontFace $face, public readonly NameType $name) {}

    public function add(FontRun $run): void
    {
        if ($this->built !== null || $run->font !== $this->face) {
            throw new \LogicException('Only matching font runs may be added before PDF embedding.');
        }
        foreach ($run->text->glyphs as $glyph) {
            $this->repertoire[$glyph->key()] = $glyph;
            if (count($this->repertoire) > 65_535) {
                throw new LimitExceeded('One embedded font exceeds its PDF character-code repertoire.');
            }
        }
    }

    public function embedding(): TrueTypeFontEmbedding
    {
        if ($this->built === null) {
            $source = $this->face->program->source;
            $flags = 4 | ($source->fixedPitch ? 1 : 0) | ($source->italicAngle != 0 ? 64 : 0);
            // StemV is an explicit descriptor estimate; glyph geometry comes from the embedded program.
            $this->built = new TrueTypeFontEmbedding($this->face->program, array_values($this->repertoire), new FontDescriptorFlags($flags), new NumericType(80));
        }

        return $this->built;
    }
}
