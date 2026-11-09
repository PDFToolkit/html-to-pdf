<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\Fonts\TrueType\UnicodeText;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDFToolkit\OpenType\Positioning\GlyphPosition;
use PDFToolkit\OpenType\Positioning\PositionedGlyphSequence;

/** Add spacing to complete source graphemes, keeping attached marks in place. */
final readonly class GlyphSpacing
{
    public function apply(UnicodeText $text, PositionedGlyphSequence $layout, TextSpacing $spacing, float $fontUnitsPerPoint): PositionedGlyphSequence
    {
        $letter = $spacing->letterSpacing ?? 0.0;
        $word = $spacing->wordSpacing ?? 0.0;
        if (($letter == 0 && $word == 0) || $text->codePoints === []) {
            return $layout;
        }
        if (!is_finite($fontUnitsPerPoint) || $fontUnitsPerPoint <= 0) {
            throw new \InvalidArgumentException('Spacing requires a positive finite font scale.');
        }
        if (preg_match_all('/\X/u', $text->utf8, $matches) === false) {
            throw new UnsupportedFeature('Text spacing requires identifiable Unicode graphemes.');
        }
        $groups = [];
        $wordCounts = [];
        $pointIndex = 0;
        foreach ($matches[0] as $group => $grapheme) {
            $wordCounts[$group] = 0;
            for ($remaining = mb_strlen($grapheme, 'UTF-8'); $remaining > 0; --$remaining) {
                $point = $text->codePoints[$pointIndex];
                $groups[$pointIndex++] = $group;
                if ($point === 0x20 || $point === 0xa0) {
                    ++$wordCounts[$group];
                }
            }
        }

        $lastGlyph = [];
        $groupAdvances = [];
        $previous = -1;
        foreach ($layout->sequence->componentClusters as $index => $components) {
            $represented = [];
            foreach ($components as $component) {
                $group = $groups[$component] ?? throw new UnsupportedFeature('Spacing provenance exceeds the source text.');
                $represented[$group] = true;
            }
            if ($letter != 0 && count($represented) !== 1) {
                throw new UnsupportedFeature('Letter spacing cannot divide a glyph shared by different graphemes.');
            }
            foreach ($represented as $group => $_) {
                if ($group < $previous || ($word != 0 && $wordCounts[$group] > 0 && count($represented) !== 1)) {
                    throw new UnsupportedFeature('Text spacing requires unambiguous, ordered grapheme positions.');
                }
                $lastGlyph[$group] = $index;
                $groupAdvances[$group] = ($groupAdvances[$group] ?? 0.0) + $layout->positions[$index]->xAdvance;
                $previous = $group;
            }
        }

        $positions = $layout->positions;
        foreach ($wordCounts as $group => $wordCount) {
            if (!isset($lastGlyph[$group])) {
                if ($letter != 0 || ($word != 0 && $wordCount > 0)) {
                    throw new UnsupportedFeature('Text spacing cannot position a source grapheme without a glyph.');
                }
                continue;
            }
            // The horizontal CSS1/CSS2.1 profile keeps the final trailing letter space,
            // as independently witnessed in Chromium and Firefox.
            $extra = $letter + (($groupAdvances[$group] ?? 0.0) == 0 ? 0.0 : $word * $wordCount);
            if ($extra == 0) {
                continue;
            }
            $index = $lastGlyph[$group];
            $position = $positions[$index];
            $advance = $position->xAdvance + $extra * $fontUnitsPerPoint;
            if (!is_finite($advance)) {
                throw new UnsupportedFeature('Text spacing exceeds finite glyph coordinates.');
            }
            $positions[$index] = new GlyphPosition($advance, $position->yAdvance, $position->xOffset, $position->yOffset);
        }

        return new PositionedGlyphSequence($layout->sequence, $positions);
    }
}
