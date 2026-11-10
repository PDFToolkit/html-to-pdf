<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

/**
 * Candidate faces for one computed font, in the order of CSS 2.1 15.2: the listed
 * families whose style matches, then the best match of the default family.
 */
final readonly class FaceChain
{
    /**
     * @param list<FamilyFace> $faces Never empty; faces are distinct font programs.
     * @param bool $listedFamilyMatched False when no listed family supplied a face (step 5 alone applies).
     * @param bool $styleRelaxed True when the default family has no face of the requested style.
     */
    public function __construct(public array $faces, public bool $listedFamilyMatched, public bool $styleRelaxed)
    {
        if ($faces === []) {
            throw new \InvalidArgumentException('A face chain needs at least one candidate.');
        }
    }

    public function primary(): FamilyFace
    {
        return $this->faces[0];
    }
}
