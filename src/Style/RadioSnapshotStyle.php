<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;

/** A static radio glyph has an explicit, deliberately narrow CSS geometry profile. */
final readonly class RadioSnapshotStyle
{
    public function validate(ComputedStyle $style): void
    {
        if ($style->display === 'none') {
            return;
        }
        if ($style->display !== 'inline' || $style->width !== null || $style->height !== null
            || $style->float !== 'none' || $style->clear !== 'none'
            || $style->margin->horizontal() !== 0.0 || $style->margin->vertical() !== 0.0
            || $style->padding->horizontal() !== 0.0 || $style->padding->vertical() !== 0.0
            || $style->borderEdges()->hasWidth() || $style->background !== null) {
            throw new UnsupportedFeature('Static radio snapshots require inline controls without CSS dimensions, float, clear, margins, padding, borders or backgrounds.');
        }
    }
}
