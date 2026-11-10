<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontSlant;

/**
 * Font matching of CSS1 5.2.1 as revised by CSS 2.1 15.2, over the caller's labeled faces.
 *
 * Pure and stateless: it reports what matched and leaves diagnostics to the layout.
 */
final readonly class FontMatcher
{
    /**
     * Steps 2, 3 and 5: every listed family that has a face of the requested style, in order,
     * then the default family with the best match it can offer. Unbound names are skipped.
     */
    public function chain(FontSet $default, FontFamilyList $families, FontSlant $slant, int $weight, bool $smallCaps = false): FaceChain
    {
        $faces = [];
        foreach ($families->names as $name) {
            $family = $default->catalog->find($name);
            $face = $family === null ? null : $this->match($family, $slant, $weight, $smallCaps);
            if ($face !== null && !$this->contains($faces, $face)) {
                $faces[] = $face;
            }
        }
        $listed = $faces !== [];
        $best = $this->best($default, $slant, $weight, $smallCaps);
        if (!$this->contains($faces, $best)) {
            $faces[] = $best;
        }

        return new FaceChain($faces, $listed, $this->match($default, $slant, $weight, $smallCaps) === null);
    }

    /** Step 2 inside one family: style first, then variant, then weight. Null when the style fails. */
    public function match(FontSet $family, FontSlant $slant, int $weight, bool $smallCaps = false): ?FamilyFace
    {
        $faces = $this->styled($family->faces, $slant);

        return $faces === [] ? null : $this->weighted($this->variant($faces, $smallCaps), $weight);
    }

    /** Step 5: the best match obtainable within the default family. A family always has a normal face. */
    public function best(FontSet $family, FontSlant $slant, int $weight, bool $smallCaps = false): FamilyFace
    {
        $order = match ($slant) {
            FontSlant::Normal => [FontSlant::Normal],
            FontSlant::Italic => [FontSlant::Italic, FontSlant::Normal],
            FontSlant::Oblique => [FontSlant::Oblique, FontSlant::Italic, FontSlant::Normal],
        };
        foreach ($order as $candidate) {
            $face = $this->match($family, $candidate, $weight, $smallCaps);
            if ($face !== null) {
                return $face;
            }
        }
        throw new \LogicException('The regular slot guarantees a normal face in every family.');
    }

    /**
     * Candidate weights for a desired weight, in the order of CSS 2.1 15.6. The rule is
     * stated by range so that it is total for any number, not only the nine steps.
     *
     * @param list<int> $available
     * @return list<int>
     */
    public function weightOrder(int $desired, array $available): array
    {
        $available = array_values(array_unique($available));
        sort($available);
        $below = array_reverse(array_values(array_filter($available, static fn(int $weight): bool => $weight < $desired)));
        $from = array_values(array_filter($available, static fn(int $weight): bool => $weight >= $desired));
        if ($desired < 400) {
            // The desired weight itself, lighter weights in descending order, then darker ones in ascending order.
            $exact = array_values(array_filter($from, static fn(int $weight): bool => $weight === $desired));
            $above = array_values(array_filter($from, static fn(int $weight): bool => $weight > $desired));

            return [...$exact, ...$below, ...$above];
        }
        if ($desired > 500) {
            // Darker weights in ascending order, then lighter ones in descending order.
            return [...$from, ...$below];
        }
        // 400 tries 500 and 500 tries 400 before the rule for weights lighter than 400.
        $middle = array_values(array_filter($from, static fn(int $weight): bool => $weight <= 500));
        $above = array_values(array_filter($from, static fn(int $weight): bool => $weight > 500));

        return [...$middle, ...$below, ...$above];
    }

    /**
     * 'italic' is satisfied by an italic face, preferably, or by an oblique one; any other
     * value must match exactly or the style fails for this family.
     *
     * @param list<FamilyFace> $faces
     * @return list<FamilyFace>
     */
    private function styled(array $faces, FontSlant $slant): array
    {
        $exact = array_values(array_filter($faces, static fn(FamilyFace $face): bool => $face->slant === $slant));
        if ($exact !== [] || $slant !== FontSlant::Italic) {
            return $exact;
        }

        return array_values(array_filter($faces, static fn(FamilyFace $face): bool => $face->slant === FontSlant::Oblique));
    }

    /**
     * A small-caps font is preferred for small-caps and avoided for normal; a family available
     * in a single variant remains selectable for both (CSS 2.1 15.2), so the variant never fails.
     *
     * @param non-empty-list<FamilyFace> $faces
     * @return non-empty-list<FamilyFace>
     */
    private function variant(array $faces, bool $smallCaps): array
    {
        $preferred = array_values(array_filter($faces, static fn(FamilyFace $face): bool => $face->smallCaps === $smallCaps));

        return $preferred !== [] ? $preferred : $faces;
    }

    /**
     * The weight never fails.
     *
     * @param non-empty-list<FamilyFace> $faces Faces of one style and one variant, hence of distinct weights.
     */
    private function weighted(array $faces, int $weight): FamilyFace
    {
        $byWeight = [];
        foreach ($faces as $face) {
            $byWeight[$face->weight] = $face;
        }

        return $byWeight[$this->weightOrder($weight, array_keys($byWeight))[0]];
    }

    /** @param list<FamilyFace> $faces */
    private function contains(array $faces, FamilyFace $candidate): bool
    {
        foreach ($faces as $face) {
            if ($face->face === $candidate->face) {
                return true;
            }
        }

        return false;
    }
}
