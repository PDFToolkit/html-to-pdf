<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Font\FontRun;

final readonly class TextBounds
{
    public function calculate(Text $command, FontRun $run, float $pageHeight): Bounds
    {
        $baseline = $pageHeight - $command->y;
        $bounds = new Bounds($command->x, $baseline - $run->metrics->descent, $command->x + $run->width, $baseline + $run->metrics->ascent);
        $source = $command->font->program->source;
        $scale = $command->size / $source->metrics->unitsPerEm;
        $advanceX = $advanceY = 0.0;
        foreach ($run->text->glyphs as $index => $glyph) {
            $position = $run->text->layout->positions[$index];
            $box = $source->outlines->boundingBox($glyph->glyph->value);
            if ($box !== null) {
                $x = $command->x + ($advanceX + $position->xOffset) * $scale;
                $y = $baseline + ($advanceY + $position->yOffset) * $scale;
                $bounds = $bounds->union(new Bounds($x + $box[0] * $scale, $y + $box[1] * $scale, $x + $box[2] * $scale, $y + $box[3] * $scale));
            }
            $advanceX += $position->xAdvance;
            $advanceY += $position->yAdvance;
        }

        return $bounds;
    }
}
