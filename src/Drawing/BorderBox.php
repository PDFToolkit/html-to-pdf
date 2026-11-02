<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Drawing;

use PDF\HtmlToPdf\Style\Borders;

/** A slice of one border box. Patterns and joins use the unsliced geometry. */
final readonly class BorderBox implements Command
{
    public function __construct(public float $x, public float $y, public float $width, public float $height, public Borders $borders, public float $offset, public float $totalHeight, public ?string $semanticId = null)
    {
        foreach ([$x, $y, $width, $height, $offset, $totalHeight] as $value) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Border geometry must be finite.');
            }
        }
        $edges = $borders->widths();
        if ($width < $edges->horizontal() || $totalHeight < $edges->vertical() || min($width, $height, $offset, $totalHeight) < 0 || $offset + $height > $totalHeight + 0.001) {
            throw new \InvalidArgumentException('Border geometry must contain its edges and slice.');
        }
    }
}
