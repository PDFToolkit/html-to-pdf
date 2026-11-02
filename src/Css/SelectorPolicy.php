<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Exception\LimitExceeded;
use Symfony\Component\CssSelector\Node\AttributeNode;
use Symfony\Component\CssSelector\Node\ClassNode;
use Symfony\Component\CssSelector\Node\CombinedSelectorNode;
use Symfony\Component\CssSelector\Node\ElementNode;
use Symfony\Component\CssSelector\Node\FunctionNode;
use Symfony\Component\CssSelector\Node\HashNode;
use Symfony\Component\CssSelector\Node\MatchingNode;
use Symfony\Component\CssSelector\Node\NegationNode;
use Symfony\Component\CssSelector\Node\NodeInterface;
use Symfony\Component\CssSelector\Node\PseudoNode;
use Symfony\Component\CssSelector\Node\SelectorNode;
use Symfony\Component\CssSelector\Node\SpecificityAdjustmentNode;

/** Checks parsed nodes, so escaped punctuation is never mistaken for a pseudo-class. */
final class SelectorPolicy
{
    public function supports(NodeInterface $node, int $maximumDepth): bool
    {
        $stack = [[$node, 1]];
        while ($stack !== []) {
            [$current, $depth] = array_pop($stack);
            if ($depth > $maximumDepth) {
                throw new LimitExceeded('CSS selector tree depth budget exhausted.');
            }
            $children = match (true) {
                $current instanceof SelectorNode => $current->getPseudoElement() === null ? [$current->getTree()] : null,
                $current instanceof ElementNode => [],
                $current instanceof PseudoNode => in_array($current->getIdentifier(), ['root', 'empty', 'first-child', 'last-child', 'only-child', 'first-of-type', 'last-of-type', 'only-of-type'], true) ? [$current->getSelector()] : null,
                $current instanceof FunctionNode => in_array($current->getName(), ['nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type'], true) ? [$current->getSelector()] : null,
                $current instanceof ClassNode, $current instanceof HashNode, $current instanceof AttributeNode => [$current->getSelector()],
                $current instanceof CombinedSelectorNode, $current instanceof NegationNode => [$current->getSelector(), $current->getSubSelector()],
                $current instanceof MatchingNode, $current instanceof SpecificityAdjustmentNode => [$current->selector, ...$current->arguments],
                default => null,
            };
            if ($children === null) {
                return false;
            }
            foreach ($children as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
        return true;
    }
}
