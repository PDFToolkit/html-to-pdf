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
 *
 * For small capitals the glyph a face must have is the one actually drawn: a face
 * labeled as a small-caps font draws the text as written, any other face draws
 * lowercase letters with the uppercase glyphs that SmallCapitals maps them to.
 * Pure and stateless; shaping, measurement and diagnostics belong to the layout.
 */
final readonly class TextItemizer
{
    private SmallCapitals $capitals;

    public function __construct()
    {
        $this->capitals = new SmallCapitals();
    }

    /**
     * @return list<TextSegment> Never empty; the texts concatenate to the input.
     */
    public function itemize(string $text, FaceChain $chain, bool $smallCaps = false): array
    {
        if ($text === '' || (!$smallCaps && count($chain->faces) === 1)) {
            return [new TextSegment($chain->primary(), $text)];
        }
        if (preg_match_all('/\X/u', $text, $matches) === false) {
            throw new \InvalidArgumentException('Font fallback requires valid UTF-8 text.');
        }
        /** @var list<array{FamilyFace,string,bool,bool,bool}> $runs */
        $runs = [];
        foreach ($matches[0] as $cluster) {
            $points = $this->codePoints($cluster);
            $choice = $this->choose($chain, $cluster, $points, $smallCaps);
            if ($choice !== null) {
                $this->append($runs, $choice[0], $cluster, false, $choice[1], $choice[2]);
                continue;
            }
            // A character absent everywhere stays with the first face, whose shaper reports it.
            $choices = array_map(fn(int $point): array => $this->choose($chain, mb_chr($point, 'UTF-8'), [$point], $smallCaps) ?? [$chain->primary(), false, false], $points);
            $divided = count(array_unique(array_map(static fn(array $choice): int => spl_object_id($choice[0]), $choices))) > 1;
            foreach ($points as $index => $point) {
                $this->append($runs, $choices[$index][0], mb_chr($point, 'UTF-8'), $divided, $choices[$index][1], $choices[$index][2]);
            }
        }

        return array_map(static fn(array $run): TextSegment => new TextSegment(...$run), $runs);
    }

    /**
     * The first face that can draw the cluster, with the way it draws it.
     *
     * @param list<int> $points
     * @return array{FamilyFace,bool,bool}|null Face, synthesized capitals, small capitals given up.
     */
    private function choose(FaceChain $chain, string $cluster, array $points, bool $smallCaps): ?array
    {
        $transformed = $smallCaps && $this->capitals->transforms($cluster);
        $upper = $transformed ? array_merge(...array_map($this->capitals->uppercase(...), $points)) : $points;
        foreach ($chain->faces as $face) {
            $synthesized = $transformed && !$face->smallCaps;
            if ($this->covers($face, $synthesized ? $upper : $points)) {
                return [$face, $synthesized, false];
            }
        }
        if ($transformed) {
            // No face has the uppercase glyphs: the letter is drawn as written rather than rejected.
            foreach ($chain->faces as $face) {
                if ($this->covers($face, $points)) {
                    return [$face, false, true];
                }
            }
        }

        return null;
    }

    /** @param list<int> $points */
    private function covers(FamilyFace $face, array $points): bool
    {
        foreach ($points as $point) {
            if (!$face->face->hasGlyph($point)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    private function codePoints(string $cluster): array
    {
        return array_map(static fn(string $character): int => mb_ord($character, 'UTF-8'), mb_str_split($cluster, 1, 'UTF-8'));
    }

    /** @param list<array{FamilyFace,string,bool,bool,bool}> $runs */
    private function append(array &$runs, FamilyFace $face, string $text, bool $split, bool $capitals, bool $unchanged): void
    {
        $last = array_key_last($runs);
        if ($last !== null && $runs[$last][0] === $face && $runs[$last][3] === $capitals) {
            $runs[$last][1] .= $text;
            $runs[$last][2] = $runs[$last][2] || $split;
            $runs[$last][4] = $runs[$last][4] || $unchanged;
        } else {
            $runs[] = [$face, $text, $split, $capitals, $unchanged];
        }
    }
}
