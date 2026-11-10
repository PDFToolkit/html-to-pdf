<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\Document\Fonts\CMaps\UnicodeSequence;
use PDF\Fonts\OpenType\GlyphText;
use PDF\Fonts\OpenType\ShapedText;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDFToolkit\OpenType\Cmap\UnicodeCharacterMap;
use PDFToolkit\OpenType\Cmap\UnicodeCmapReader;
use PDFToolkit\OpenType\Layout\GdefReader;
use PDFToolkit\OpenType\Layout\GdefTable;
use PDFToolkit\OpenType\Metrics\HorizontalMetrics;
use PDFToolkit\OpenType\Metrics\HorizontalMetricsReader;
use PDFToolkit\OpenType\Positioning\GlyphPosition;
use PDFToolkit\OpenType\Positioning\GposReader;
use PDFToolkit\OpenType\Positioning\GposTable;
use PDFToolkit\OpenType\Positioning\PositionedGlyphSequence;
use PDFToolkit\OpenType\Positioning\PositioningEngine;
use PDFToolkit\OpenType\Sfnt\ReadLimits;
use PDFToolkit\OpenType\Sfnt\SfntFont;
use PDFToolkit\OpenType\Substitution\FeatureSelection;
use PDFToolkit\OpenType\Substitution\GlyphSequence;
use PDFToolkit\OpenType\Substitution\GsubReader;
use PDFToolkit\OpenType\Substitution\GsubTable;
use PDFToolkit\OpenType\Substitution\SubstitutionEngine;
use PDFToolkit\OpenType\Value\CodePoint;
use PDFToolkit\OpenType\Value\GlyphId;
use PDFToolkit\OpenType\Value\Tag;

/**
 * Immutable, face-scoped tables; shaping engines allocate fresh budgets per run.
 * Unicode provenance follows PDFToolkit pdf-fonts 0.2 TextLayout (MIT,
 * Copyright (c) 2026 Mathias STRASSER; see the repository LICENSE).
 */
final readonly class PreparedLayout
{
    private UnicodeCharacterMap $map;
    private HorizontalMetrics $metrics;
    private ?GdefTable $definition;
    private ?GsubTable $substitution;
    private ?GposTable $positioning;
    private FeatureSelection $features;
    private FeatureSelection $spacedFeatures;

    public function __construct(private SfntFont $font, private ReadLimits $limits, ?UnicodeCharacterMap $map = null)
    {
        foreach (['fvar', 'CFF2'] as $tag) {
            if ($font->hasTable(new Tag($tag))) {
                throw new \InvalidArgumentException('This adapter requires a static font instance.');
            }
        }
        $this->map = $map ?? (new UnicodeCmapReader($limits))->read($font->table(new Tag('cmap')), $font->glyphCount());
        $this->metrics = (new HorizontalMetricsReader())->read($font);
        $this->definition = $font->hasTable(new Tag('GDEF')) ? (new GdefReader($limits))->read($font->table(new Tag('GDEF')), $font->glyphCount()) : null;
        $this->substitution = $font->hasTable(new Tag('GSUB')) ? (new GsubReader($limits))->read($font->table(new Tag('GSUB')), $font->glyphCount()) : null;
        $this->positioning = $font->hasTable(new Tag('GPOS')) ? (new GposReader($limits))->read($font->table(new Tag('GPOS')), $font->glyphCount()) : null;
        $this->features = new FeatureSelection(new Tag('latn'), features: [new Tag('liga'), new Tag('kern'), new Tag('mark')]);
        $this->spacedFeatures = new FeatureSelection(new Tag('latn'), features: [new Tag('kern'), new Tag('mark')]);
    }

    public function create(UnicodeText $text, TextSpacing $spacing = new TextSpacing(), float $fontUnitsPerPoint = 1): ShapedText
    {
        if (count($text->codePoints) > $this->limits->maxOutputGlyphs) {
            throw new \InvalidArgumentException('Layout inputs exceed the explicit glyph budget.');
        }
        $glyphs = [];
        foreach ($text->codePoints as $point) {
            $glyph = $this->map->glyphFor(new CodePoint($point));
            if ($glyph->value === 0) {
                throw new \InvalidArgumentException(sprintf('No glyph for U+%04X.', $point));
            }
            $glyphs[] = $glyph;
        }
        $sequence = new GlyphSequence($glyphs);
        $features = ($spacing->letterSpacing ?? 0) == 0 ? $this->features : $this->spacedFeatures;
        if ($this->substitution !== null) {
            $sequence = (new SubstitutionEngine($this->limits))->apply($this->substitution, $features, $sequence, $this->definition);
        }
        $positions = array_map(fn(GlyphId $glyph): GlyphPosition => new GlyphPosition($this->metrics->advanceWidth($glyph)), $sequence->glyphs);
        $positioned = new PositionedGlyphSequence($sequence, $positions);
        if ($this->positioning !== null) {
            $positioned = (new PositioningEngine($this->limits))->apply($this->positioning, $features, $positioned, $this->definition);
        }
        $positioned = (new GlyphSpacing())->apply($text, $positioned, $spacing, $fontUnitsPerPoint);

        $seen = [];
        $mapped = [];
        foreach ($sequence->glyphs as $index => $glyph) {
            $bytes = '';
            foreach ($sequence->componentClusters[$index] as $cluster) {
                if (!isset($seen[$cluster])) {
                    $point = $text->codePoints[$cluster] ?? throw new \LogicException('Layout provenance exceeds source text.');
                    $bytes .= $point < 65536 ? pack('n', $point) : pack('nn', 0xD800 + (($point - 0x10000) >> 10), 0xDC00 + (($point - 0x10000) & 1023));
                    $seen[$cluster] = true;
                }
            }
            $mapped[] = new GlyphText($glyph, new UnicodeSequence(new HexadecimalString($bytes)));
        }

        return new ShapedText($this->font, $text, $positioned, $mapped);
    }
}
