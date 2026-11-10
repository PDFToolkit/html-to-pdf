<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

/** A maximal run of source text drawn with one face of a chain. */
final readonly class TextSegment
{
    /**
     * @param bool $split True when a grapheme cluster of this run is shared with a neighbouring face.
     * @param bool $capitals True when the run is drawn as synthesized small capitals.
     * @param bool $unchanged True when small capitals were requested but no face has the uppercase glyphs.
     */
    public function __construct(public FamilyFace $face, public string $text, public bool $split = false, public bool $capitals = false, public bool $unchanged = false) {}
}
