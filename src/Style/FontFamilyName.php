<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** Quoted family names remain distinct from the five generic CSS1 keywords. */
final readonly class FontFamilyName
{
    public function __construct(public string $name, public bool $generic = false)
    {
        if (trim($name) === '' || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1
            || ($generic && !in_array(strtolower($name), ['serif', 'sans-serif', 'cursive', 'fantasy', 'monospace'], true))) {
            throw new \InvalidArgumentException('Expected a nonempty font family name or a CSS1 generic family.');
        }
    }

    public function key(): string
    {
        return mb_strtolower($this->name, 'UTF-8');
    }

    public function css(): string
    {
        return $this->generic ? strtolower($this->name) : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->name) . '"';
    }
}
