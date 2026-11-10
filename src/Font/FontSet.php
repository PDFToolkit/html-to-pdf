<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontSlant;

/** Explicit variants, with no operating-system font lookup or synthetic bold/italic. */
final readonly class FontSet
{
    /** @var list<FamilyFace> The four named slots labeled by position, then the additional faces. */
    public array $faces;

    /**
     * The named slots are normal 400, normal 700, italic 400 and italic 700.
     *
     * @param list<FamilyFace> $faces Additional faces with explicit labels, such as light, black, oblique or small-caps.
     */
    public function __construct(
        public FontFace $regular,
        public ?FontFace $bold = null,
        public ?FontFace $italic = null,
        public ?FontFace $boldItalic = null,
        public FontCatalog $catalog = new FontCatalog(),
        array $faces = [],
    ) {
        $this->requireFaces($faces);
        $labeled = [new FamilyFace($regular)];
        if ($bold !== null) {
            $labeled[] = new FamilyFace($bold, 700);
        }
        if ($italic !== null) {
            $labeled[] = new FamilyFace($italic, 400, FontSlant::Italic);
        }
        if ($boldItalic !== null) {
            $labeled[] = new FamilyFace($boldItalic, 700, FontSlant::Italic);
        }
        array_push($labeled, ...$faces);
        // Distinct labels also bound a family: three styles, two variants and nine weights.
        $keys = [];
        foreach ($labeled as $face) {
            if (isset($keys[$face->key()])) {
                throw new \InvalidArgumentException('A family cannot declare two faces with the same style, variant and weight: ' . $face->key());
            }
            $keys[$face->key()] = true;
        }
        $this->faces = $labeled;
    }

    private function requireFaces(mixed $faces): void
    {
        if (!is_array($faces) || !array_is_list($faces)) {
            throw new \InvalidArgumentException('Additional family faces must be an ordered list.');
        }
        foreach ($faces as $face) {
            if (!$face instanceof FamilyFace) {
                throw new \InvalidArgumentException('Additional family faces must be explicit FamilyFace objects.');
            }
        }
    }

    /**
     * Exact lookup of a named slot, failing when it is absent. Layout does not use it:
     * text is matched with the CSS rules of FontMatcher, which never fail on a weight.
     */
    public function face(FontStyle $style, ?FontFamilyList $families = null): FontFace
    {
        if ($families !== null && $families->names !== []) {
            return $this->catalog->select($families)->face($style);
        }
        $face = match ($style) {
            FontStyle::Regular => $this->regular,
            FontStyle::Bold => $this->bold,
            FontStyle::Italic => $this->italic,
            FontStyle::BoldItalic => $this->boldItalic,
        };

        return $face ?? throw new UnsupportedFeature('Supply the ' . $style->value . ' font variant explicitly.');
    }
}
