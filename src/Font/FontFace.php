<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\Fonts\OpenType\TrueTypeFontProgram;
use PDF\Fonts\TrueType\FontProgram;
use PDF\Fonts\TrueType\FontProgramLimits;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDFToolkit\OpenType\Positioning\GlyphPosition;

/** A caller-supplied, embeddable static TrueType face. No font discovery or file access. */
final readonly class FontFace
{
    public TrueTypeFontProgram $program;
    private LayoutPreparation $layout;

    public function __construct(string $bytes, private FontLimits $limits = new FontLimits())
    {
        $reading = $limits->readingLimits();
        $this->program = new TrueTypeFontProgram(new FontProgram($bytes, new FontProgramLimits($limits->maxBytes)), $reading);
        $this->layout = new LayoutPreparation($this->program->font, $reading);
    }

    public function measure(string $text, float $size, TextSpacing $spacing = new TextSpacing(), bool $capitals = false): float
    {
        return $this->shape($text, $size, $spacing, $capitals)->width;
    }

    /** With $capitals the uppercase mapping of the text is shaped, while the run keeps the source text. */
    public function shape(string $text, float $size, TextSpacing $spacing = new TextSpacing(), bool $capitals = false): FontRun
    {
        $metrics = $this->metrics($size);
        if (strlen($text) > $this->limits->maxTextBytes || preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('Text must be valid UTF-8 within the explicit font byte budget.');
        }
        if (preg_match('/[^\p{Latin}\p{Greek}\p{Cyrillic}\p{Common}\p{Inherited}]/u', $text) === 1
            || preg_match('/[\x{200C}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u', $text) === 1) {
            throw new UnsupportedFeature('This horizontal font profile supports Latin, Greek and Cyrillic, without bidirectional or complex-script shaping.');
        }
        try {
            $shaped = $this->layout->create(new UnicodeText($text), $spacing, $this->program->source->metrics->unitsPerEm / $size, $capitals);
        } catch (\InvalidArgumentException $error) {
            throw new UnsupportedFeature('The selected font cannot shape this text: ' . $error->getMessage(), previous: $error);
        }
        $width = array_sum(array_map(static fn(GlyphPosition $position): float => $position->xAdvance, $shaped->layout->positions)) * $size / $this->program->source->metrics->unitsPerEm;

        if (!is_finite($width) || $width < 0) {
            throw new UnsupportedFeature('Text spacing must retain a finite non-negative run advance.');
        }

        return new FontRun($this, $shaped, $size, $width, $metrics, $spacing);
    }

    /** Whether the face maps one Unicode code point to a glyph, as the shaper will see it. */
    public function hasGlyph(int $codePoint): bool
    {
        try {
            return $this->layout->covers($codePoint);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            throw new UnsupportedFeature('The character map of the selected font cannot be read: ' . $error->getMessage(), previous: $error);
        }
    }

    public function metrics(float $size): FontMetrics
    {
        if (!is_finite($size) || $size <= 0 || $size > 10_000) {
            throw new \InvalidArgumentException('Font sizes must be positive finite PDF points, at most 10000.');
        }
        $source = $this->program->source;
        $scale = $size / $source->metrics->unitsPerEm;

        return new FontMetrics($source->ascent * $scale, -$source->descent * $scale, max(0, $source->table('OS/2')->int16(72)) * $scale);
    }
}
