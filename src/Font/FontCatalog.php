<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontFamilyName;

/** Caller-supplied family and generic bindings only; no filesystem or network lookup. */
final readonly class FontCatalog
{
    /** @var array<string,FontSet> */
    private array $families;
    /** @var array<string,FontSet> */
    private array $genericFamilies;

    /** @param array<string,FontSet> $families
     * @param array<string,FontSet> $genericFamilies
     */
    public function __construct(array $families = [], array $genericFamilies = [])
    {
        $this->families = $this->normalize($families, false);
        $this->genericFamilies = $this->normalize($genericFamilies, true);
    }

    public function select(FontFamilyList $list): FontSet
    {
        foreach ($list->names as $family) {
            $set = ($family->generic ? $this->genericFamilies : $this->families)[$family->key()] ?? null;
            if ($set !== null) {
                return $set;
            }
        }
        throw new UnsupportedFeature('No explicitly supplied font family matches: ' . $list->css());
    }

    private function requireBinding(mixed $name, mixed $set): void
    {
        if (!is_string($name) || !$set instanceof FontSet) {
            throw new \InvalidArgumentException('Font bindings require a family name and a FontSet.');
        }
    }

    /** @param array<string,FontSet> $sets
     * @return array<string,FontSet>
     */
    private function normalize(array $sets, bool $generic): array
    {
        $result = [];
        foreach ($sets as $name => $set) {
            $this->requireBinding($name, $set);
            $family = new FontFamilyName($name, $generic);
            if (isset($result[$family->key()])) {
                throw new \InvalidArgumentException('Font family bindings must be unique ignoring case.');
            }
            $result[$family->key()] = $set;
        }
        return $result;
    }
}
