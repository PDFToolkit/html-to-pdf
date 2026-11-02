<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** An empty list selects the caller's original default FontSet. */
final readonly class FontFamilyList
{
    /** @param list<FontFamilyName> $names */
    public function __construct(public array $names = [])
    {
        $this->requireNames($names);
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
