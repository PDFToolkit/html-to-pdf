<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

/**
 * Step 4 of CSS1 5.2.1: a character without a glyph in the matching face is taken
 * from the next candidate face.
 *
 * The unit is the extended grapheme cluster, so a base and its combining marks stay
 * in one face whenever one face has them all. Only when no face covers a whole
 * cluster is it divided character by character, as the specification words it.
 * Pure and stateless; shaping, measurement and diagnostics belong to the layout.
 */
final readonly class TextItemizer
{
    /**
     * @return list<TextSegment> Never empty; the texts concatenate to the input.
     */
    public function itemize(string $text, FaceChain $chain): array
    {
        if (count($chain->faces) === 1 || $text === '') {
            return [new TextSegment($chain->primary(), $text)];
        }
        if (preg_match_all('/\X/u', $text, $matches) === false) {
            throw new \InvalidArgumentException('Font fallback requires valid UTF-8 text.');
        }
        /** @var list<array{FamilyFace,string,bool}> $runs */
        $runs = [];
        foreach ($matches[0] as $cluster) {
            $points = $this->codePoints($cluster);
            $face = $this->covering($chain, $points);
            if ($face !== null) {
                $this->append($runs, $face, $cluster, false);
                continue;
            }
            // A character absent everywhere stays with the first face, whose shaper reports it.
            $faces = array_map(fn(int $point): FamilyFace => $this->covering($chain, [$point]) ?? $chain->primary(), $points);
            $divided = count(array_unique(array_map(spl_object_id(...), $faces))) > 1;
            foreach ($points as $index => $point) {
                $this->append($runs, $faces[$index], mb_chr($point, 'UTF-8'), $divided);
            }
        }

        return array_map(static fn(array $run): TextSegment => new TextSegment($run[0], $run[1], $run[2]), $runs);
    }

    /** @param list<int> $points */
    private function covering(FaceChain $chain, array $points): ?FamilyFace
    {
        foreach ($chain->faces as $face) {
            foreach ($points as $point) {
                if (!$face->face->hasGlyph($point)) {
                    continue 2;
                }
            }

            return $face;
        }

        return null;
    }

    /** @return list<int> */
    private function codePoints(string $cluster): array
    {
        return array_map(static fn(string $character): int => mb_ord($character, 'UTF-8'), mb_str_split($cluster, 1, 'UTF-8'));
    }

    /** @param list<array{FamilyFace,string,bool}> $runs */
    private function append(array &$runs, FamilyFace $face, string $text, bool $split): void
    {
        $last = array_key_last($runs);
        if ($last !== null && $runs[$last][0] === $face) {
            $runs[$last][1] .= $text;
            $runs[$last][2] = $runs[$last][2] || $split;
        } else {
            $runs[] = [$face, $text, $split];
        }
    }
}
