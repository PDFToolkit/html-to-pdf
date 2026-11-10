<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** An empty list selects the caller's original default FontSet. */
final readonly class FontFamilyList
{
    /** Every name is a candidate family for every character, so the list itself is bounded. */
    public const int MAX_NAMES = 64;

    /** @param list<FontFamilyName> $names */
    public function __construct(public array $names = [])
    {
        $this->requireNames($names);
        if (count($names) > self::MAX_NAMES) {
            throw new \InvalidArgumentException('A font family list is limited to ' . self::MAX_NAMES . ' names.');
        }
    }

    private function requireNames(mixed $names): void
    {
        if (!is_array($names) || !array_is_list($names)) {
            throw new \InvalidArgumentException('Font families must be an ordered list.');
        }
        foreach ($names as $name) {
            if (!$name instanceof FontFamilyName) {
                throw new \InvalidArgumentException('Font families must contain explicit family names.');
            }
        }
    }

    public function css(): string
    {
        return implode(', ', array_map(static fn(FontFamilyName $name): string => $name->css(), $this->names));
    }
}
