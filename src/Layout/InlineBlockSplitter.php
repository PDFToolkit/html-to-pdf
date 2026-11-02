<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Model\Node;

/** Split inline ancestors around block descendants without discarding text or inherited styles. */
final class InlineBlockSplitter
{
    private int $sequence = 0;

    /** @param list<Node> $children
     * @return list<Node>
     */
    public function split(array $children): array
    {
        $result = [];
        foreach ($children as $node) {
            if ($node->isText() || $node->style->display !== 'inline' || $node->children === []) {
                $result[] = $node;
                continue;
            }
            $parts = $this->split($node->children);
            $hasBlock = false;
            foreach ($parts as $part) {
                $hasBlock = $hasBlock || (!$part->isText() && in_array($part->style->display, ['block', 'list-item', 'table'], true));
            }
            if (!$hasBlock) {
                $result[] = $node;
                continue;
            }
            if ($node->tag === 'a' || $node->attribute('lang') !== null) {
                throw new UnsupportedFeature('Links or explicit language scopes spanning block descendants are outside this layout profile.');
            }
            if ($node->style->background !== null || $node->style->borderEdges()->hasWidth() || $node->style->padding->horizontal() > 0 || $node->style->padding->vertical() > 0 || $node->style->margin->horizontal() > 0) {
                throw new UnsupportedFeature('Decorated inline boxes split around block descendants are outside this layout profile.');
            }
            $inline = [];
            foreach ($parts as $part) {
                if (!$part->isText() && in_array($part->style->display, ['block', 'list-item', 'table'], true)) {
                    if ($inline !== []) {
                        $result[] = $this->fragment($node, $inline);
                        $inline = [];
                    }
                    $result[] = $part;
                } else {
                    $inline[] = $part;
                }
            }
            if ($inline !== []) {
                $result[] = $this->fragment($node, $inline);
            }
        }
        return $result;
    }

    /** @param list<Node> $children */
    private function fragment(Node $node, array $children): Node
    {
        return new Node($node->id . ':inline-fragment-' . ++$this->sequence, $node->tag, $node->attributes, $children, style: $node->style);
    }
}
