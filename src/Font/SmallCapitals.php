<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

/**
 * Synthesis of CSS1 5.2.4: lowercase letters drawn as scaled uppercase glyphs of a normal face.
 *
 * The mapping is the unconditional Unicode uppercase mapping, without language-specific
 * rules; CSS1 allows that limit for this property as for text-transform.
 */
final readonly class SmallCapitals
{
    /** CSS1 leaves the scale to the user agent; one browser witness uses exactly this value. */
    public const float SCALE = 0.8;

    /** True when a grapheme cluster is drawn with capitals, that is when uppercasing changes it. */
    public function transforms(string $cluster): bool
    {
        return mb_strtoupper($cluster, 'UTF-8') !== $cluster;
    }

    /**
     * The code points drawn for one source code point: itself, or its full uppercase mapping.
     *
     * @return non-empty-list<int>
     */
    public function uppercase(int $codePoint): array
    {
        $points = [];
        foreach (mb_str_split(mb_strtoupper(mb_chr($codePoint, 'UTF-8'), 'UTF-8'), 1, 'UTF-8') as $character) {
            $points[] = mb_ord($character, 'UTF-8');
        }

        return $points === [] ? [$codePoint] : $points;
    }
}
