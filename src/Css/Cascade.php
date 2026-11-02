<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Parsing\ParseBudget;
use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
use Sabberworm\CSS\Value\Value;
use Symfony\Component\CssSelector\Exception\ExceptionInterface;
use Symfony\Component\CssSelector\Node\Specificity;
use Symfony\Component\CssSelector\Parser\Parser as SelectorParser;
use Symfony\Component\CssSelector\XPath\Extension\HtmlExtension;
use Symfony\Component\CssSelector\XPath\Translator;

/** Author cascade for a single DOM; no global selector cache or resource retrieval. */
final class Cascade
{
    /** @var array<string,array<string,CascadedValue>> */
    private array $values = [];
    private int $order = 0;
    private readonly \DOMXPath $xpath;
    private readonly Translator $translator;
    private readonly Declarations $declarations;
    private readonly int $elementCount;

    public function __construct(private readonly \DOMDocument $document, private readonly ParseBudget $budget, private readonly Diagnostics $diagnostics)
    {
        $this->xpath = new \DOMXPath($document);
        $this->translator = new Translator();
        $this->translator->registerExtension(new HtmlExtension($this->translator));
        $this->declarations = new Declarations($diagnostics);
        $this->elementCount = $document->getElementsByTagName('*')->length;
    }

    public function stylesheet(string $css): void
    {
        $this->budget->css($css);
        try {
            $parsed = (new Parser($css, Settings::create()->beStrict()))->parse();
        } catch (SourceException $error) {
            $this->diagnostics->unsupported('css.syntax', 'CSS cannot be parsed: ' . $error->getMessage());
            return;
        }
        $this->blocks($parsed);
    }

    public function inline(\DOMElement $element, string $css): void
    {
        $this->budget->css($css);
        try {
            $parsed = (new Parser('x{' . $css . '}', Settings::create()->beStrict()))->parse();
        } catch (SourceException $error) {
            $this->diagnostics->unsupported('css.syntax', 'Inline CSS cannot be parsed: ' . $error->getMessage());
            return;
        }
        $contents = $parsed->getContents();
        if (count($contents) !== 1 || !$contents[0] instanceof DeclarationBlock) {
            $this->diagnostics->unsupported('css.inline', 'An inline style must contain declarations only.');
            return;
        }
        $this->apply($contents[0], [$element], new Specificity(0, 0, 0), true);
    }

    /** @return array<string,string> */
    public function forElement(\DOMElement $element): array
    {
        return array_map(static fn(CascadedValue $value): string => $value->value, $this->values[$element->getNodePath()] ?? []);
    }

    public function printMedia(string $media): bool
    {
        if (trim($media) === '') {
            return true;
        }
        $matches = false;
        foreach (explode(',', strtolower($media)) as $query) {
            $query = trim($query);
            if (in_array($query, ['all', 'print', 'only print', 'not screen'], true)) {
                $matches = true;
            } elseif (!in_array($query, ['screen', 'only screen', 'not print', 'not all'], true)) {
                $this->diagnostics->unsupported('css.media', 'Unsupported print media query: ' . $query);
            }
        }
        return $matches;
    }

    private function blocks(CSSList $list): void
    {
        foreach ($list->getContents() as $block) {
            if ($block instanceof AtRuleBlockList && strtolower($block->atRuleName()) === 'media') {
                if ($this->printMedia($block->atRuleArgs())) {
                    $this->blocks($block);
                }
                continue;
            }
            if (!$block instanceof DeclarationBlock) {
                $this->diagnostics->unsupported('css.at-rule', 'Only print/all @media rules are supported; other CSS at-rules are not executed.');
                continue;
            }
            foreach ($block->getSelectors() as $selector) {
                $text = $selector->render(new OutputFormat());
                $this->budget->selector($text, $this->elementCount);
                if (!$this->supportedSelector($text)) {
                    continue;
                }
                try {
                    $parsed = (new SelectorParser())->parse($text);
                    if (count($parsed) !== 1 || $parsed[0]->getPseudoElement() !== null) {
                        $this->diagnostics->unsupported('css.selector', 'Pseudo-elements are not supported: ' . $text);
                        continue;
                    }
                    $expression = $this->translator->cssToXPath($text);
                    $matches = $this->xpath->query($expression);
                } catch (ExceptionInterface $error) {
                    $this->diagnostics->unsupported('css.selector', 'Unsupported selector: ' . $text . ': ' . $error->getMessage());
                    continue;
                }
                $elements = [];
                if ($matches !== false) {
                    foreach ($matches as $match) {
                        if ($match instanceof \DOMElement) {
                            $elements[] = $match;
                        }
                    }
                }
                $this->apply($block, $elements, $parsed[0]->getSpecificity(), false);
            }
        }
    }

    private function supportedSelector(string $selector): bool
    {
        // Escaped identifiers and dynamic pseudo-classes have no published v0.1 contract.
        if (str_contains($selector, '\\')) {
            $this->diagnostics->unsupported('css.selector', 'Escaped CSS selectors are outside the supported profile.');
            return false;
        }
        $unquoted = preg_replace('/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'/s', '', $selector) ?? '';
        preg_match_all('/:([a-zA-Z-]+)/', $unquoted, $names);
        $allowed = ['root', 'empty', 'first-child', 'last-child', 'only-child', 'nth-child', 'nth-last-child', 'first-of-type', 'last-of-type', 'only-of-type', 'nth-of-type', 'nth-last-of-type', 'not', 'is', 'where'];
        foreach ($names[1] as $name) {
            if (!in_array(strtolower($name), $allowed, true)) {
                $this->diagnostics->unsupported('css.selector', 'Unsupported CSS pseudo-class: ' . $name);
                return false;
            }
        }
        return true;
    }

    /** @param list<\DOMElement> $elements */
    private function apply(DeclarationBlock $block, array $elements, Specificity $specificity, bool $inline): void
    {
        foreach ($block->getDeclarations() as $declaration) {
            $raw = $declaration->getValue();
            $value = $raw instanceof Value ? $raw->render(new OutputFormat()) : (string) $raw;
            $expanded = $this->declarations->expand($declaration->getPropertyName(), $value);
            $order = ++$this->order;
            foreach ($expanded as $property => $propertyValue) {
                $candidate = new CascadedValue($propertyValue, $declaration->getIsImportant(), $inline, $specificity, $order);
                foreach ($elements as $element) {
                    $path = $element->getNodePath();
                    $previous = $this->values[$path][$property] ?? null;
                    if ($previous === null || $candidate->overrides($previous)) {
                        $this->values[$path][$property] = $candidate;
                    }
                }
            }
        }
    }
}
