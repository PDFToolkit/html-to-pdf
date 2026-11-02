<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Model\SemanticNode;

/** A new tree is allocated for each document; source identifiers never become PDF names. */
final class SemanticTree
{
    /** @var list<SemanticNode> */
    private array $nodes = [];
    /** @var array<string, string> */
    private array $mapped = [];
    private int $sequence = 0;

    public function add(string $role, ?string $parent = null, ?string $alt = null, ?string $language = null, ?string $scope = null, ?bool $checked = null): string
    {
        $id = 'structure-' . ++$this->sequence;
        $this->nodes[] = new SemanticNode($id, $role, $parent, $alt, $language, $scope, $checked);

        return $id;
    }

    public function forNode(Node $node, string $parent): string
    {
        if ($node->isText() || in_array($node->tag, ['br', 'thead', 'tbody', 'tfoot'], true)) {
            return $parent;
        }
        if (isset($this->mapped[$node->id])) {
            return $this->mapped[$node->id];
        }
        $role = match ($node->tag) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => strtoupper($node->tag),
            'p', 'pre', 'caption', 'dt' => 'P',
            'ul', 'ol' => 'L',
            'li' => 'LI',
            'table' => 'Table',
            'tr' => 'TR',
            'th' => 'TH',
            'td' => 'TD',
            'img', 'figure' => 'Figure',
            'a' => 'Link',
            'blockquote' => 'BlockQuote',
            'input' => 'Form',
            'dl', 'dd', 'form', 'div', 'section', 'article', 'main', 'header', 'footer', 'nav', 'aside' => 'Sect',
            default => 'Span',
        };

        $scope = $node->tag === 'th' ? match ($node->attribute('scope')) {
            'row' => 'Row',
            'col' => 'Column',
            null, '' => null,
            default => throw new \PDF\HtmlToPdf\Exception\UnsupportedFeature('Only row and col table header scopes are supported.'),
        } : null;

        return $this->mapped[$node->id] = $this->add($role, $parent, $role === 'Figure' ? $node->attribute('alt') : ($role === 'Form' ? 'Radio button: ' . ($node->attribute('name') ?? '') . (isset($node->attributes['checked']) ? ', checked' : ', unchecked') . (isset($node->attributes['disabled']) ? ', disabled' : '') : null), $node->attribute('lang'), $scope, $role === 'Form' ? isset($node->attributes['checked']) : null);
    }

    /** @return list<SemanticNode> */
    public function all(): array
    {
        return $this->nodes;
    }
}
