<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\HtmlToPdf\Style\FontSlant;

/**
 * One face of a family with the CSS properties its caller declares for it.
 *
 * Labels are explicit data of the caller's font database (CSS1 5.2.1): they are
 * never guessed from a file name, and no bold, italic or oblique is synthesized.
 */
final readonly class FamilyFace
{
    public function __construct(public FontFace $face, public int $weight = 400, public FontSlant $slant = FontSlant::Normal, public bool $smallCaps = false)
    {
        if ($weight < 100 || $weight > 900 || $weight % 100 !== 0) {
            throw new \InvalidArgumentException('A face weight must be one of the nine CSS steps from 100 to 900.');
        }
    }

    /** Two faces with the same key are indistinguishable for CSS font matching. */
    public function key(): string
    {
        return $this->slant->value . ':' . ($this->smallCaps ? 'small-caps' : 'normal') . ':' . $this->weight;
    }
}
