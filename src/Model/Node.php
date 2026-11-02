<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Model;

use PDF\HtmlToPdf\Style\ComputedStyle;

final readonly class Node
{
    /**
     * @param array<string,string> $attributes
     * @param list<Node> $children
     */
    public function __construct(public string $id, public string $tag, public array $attributes = [], public array $children = [], public string $text = '', public ComputedStyle $style = new ComputedStyle()) {}
    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }
    public function isText(): bool
    {
        return $this->tag === '#text';
    }
}
