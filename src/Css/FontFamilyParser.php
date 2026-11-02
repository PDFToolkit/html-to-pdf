<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontFamilyName;

final readonly class FontFamilyParser
{
    public function parse(string $value): FontFamilyList
    {
        return $this->tokens((new FontValueTokenizer())->tokenize($value));
    }

    /** @param list<FontValueToken> $tokens */
    public function tokens(array $tokens): FontFamilyList
    {
        $families = [];
        $parts = [];
        foreach ($tokens as $token) {
            if (!$token->quoted && $token->value === ',') {
                $families[] = $this->family($parts);
                $parts = [];
            } else {
                $parts[] = $token;
            }
        }
        $families[] = $this->family($parts);
        return new FontFamilyList($families);
    }

    /** @param list<FontValueToken> $parts */
    private function family(array $parts): FontFamilyName
    {
        if (count($parts) === 1 && $parts[0]->quoted) {
            return new FontFamilyName($parts[0]->value);
        }
        if ($parts === []) {
            throw new \InvalidArgumentException('A family list cannot contain an empty family.');
        }
        foreach ($parts as $part) {
            if ($part->quoted || preg_match('/^-?[_a-z\x{80}-\x{10ffff}][_a-z0-9\-\x{80}-\x{10ffff}]*$/iuD', $part->value) !== 1
                || in_array(strtolower($part->value), ['inherit', 'initial', 'unset'], true)) {
                throw new \InvalidArgumentException('Expected a quoted family or a sequence of family identifiers.');
            }
        }
        $name = implode(' ', array_map(static fn(FontValueToken $part): string => $part->value, $parts));
        $generic = in_array(strtolower($name), ['serif', 'sans-serif', 'cursive', 'fantasy', 'monospace'], true);
        return new FontFamilyName($generic ? strtolower($name) : $name, $generic);
    }
}
