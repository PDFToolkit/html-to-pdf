<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Parsing\ParseBudget;
use PDF\HtmlToPdf\Resource\CatalogUri;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Property\Charset;
use Sabberworm\CSS\Property\Import;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
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
    /** @var array<string,true> Active imports only; repeated independent imports must still cascade. */
    private array $imports = [];

    public function __construct(private readonly \DOMDocument $document, private readonly ParseBudget $budget, private readonly Diagnostics $diagnostics, private readonly ResourceCatalog $resources = new ResourceCatalog())
    {
        $this->xpath = new \DOMXPath($document);
        $this->translator = new Translator();
        $this->translator->registerExtension(new HtmlExtension($this->translator));
        $this->declarations = new Declarations($diagnostics);
        $this->elementCount = $document->getElementsByTagName('*')->length;
    }

    public function stylesheet(string $css, ?string $baseUri = null): void
    {
        $this->budget->css($css);
        $parsed = $this->parse($css);
        if ($parsed !== null) {
            $this->blocks($parsed, new DeclarationSource($css), $baseUri, 0, true);
        }
    }

    public function linkedStylesheet(string $uri, ?string $baseUri = null): void
    {
        $resolved = (new CatalogUri())->resolve($uri, $baseUri);
        $this->load($resolved, 0);
    }

    private function parse(string $css): ?CSSList
    {
        $projected = (new ParserProjection($this->diagnostics))->project($css);
        if ($projected === null) {
            return null;
        }
        try {
            return (new Parser($projected, Settings::create()->beStrict()))->parse();
        } catch (\ValueError $error) {
            // Defense in depth for third-party encoding handling after malformed syntax.
            $this->diagnostics->unsupported('css.charset', 'CSS parser rejected the stylesheet encoding: ' . $error->getMessage());
            return null;
        } catch (SourceException $error) {
            $this->diagnostics->unsupported('css.syntax', 'CSS cannot be parsed: ' . $error->getMessage());
            // Reject throws above. Report recovers only within this already-budgeted source.
            try {
                return (new Parser($projected, Settings::create()->withLenientParsing()))->parse();
            } catch (\ValueError $recoveryError) {
                $this->diagnostics->unsupported('css.charset', 'CSS recovery rejected the stylesheet encoding: ' . $recoveryError->getMessage());
                return null;
            } catch (SourceException $recoveryError) {
                $this->diagnostics->unsupported('css.syntax', 'CSS recovery stopped: ' . $recoveryError->getMessage());
                return null;
            }
        }
    }

    public function inline(\DOMElement $element, string $css): void
    {
        $this->budget->css($css);
        $wrapped = 'x{' . $css . '}';
        $parsed = $this->parse($wrapped);
        if ($parsed === null) {
            return;
        }
        $contents = $parsed->getContents();
        if (count($contents) !== 1 || !$contents[0] instanceof DeclarationBlock) {
            $this->diagnostics->unsupported('css.inline', 'An inline style must contain declarations only.');
            return;
        }
        $this->apply($this->prepare($contents[0], new DeclarationSource($wrapped)), [$element], new Specificity(0, 0, 0), true);
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

    private function blocks(CSSList $list, DeclarationSource $source, ?string $baseUri, int $depth, bool $topLevel): void
    {
        $importsAllowed = $topLevel;
        foreach ($list->getContents() as $block) {
            if ($block instanceof Charset) {
                if (!in_array(strtolower($block->getCharset()), ['utf-8', 'utf8'], true)) {
                    $this->diagnostics->unsupported('css.charset', 'Only explicitly supplied UTF-8 stylesheets are supported.');
                    return;
                }
                continue;
            }
            if ($block instanceof Import) {
                $this->budget->import($depth + 1);
                if (!$importsAllowed) {
                    $this->diagnostics->unsupported('css.import-order', '@import is allowed only before other rules at stylesheet top level.');
                } elseif ($this->printMedia($block->getMediaQuery() ?? '')) {
                    $uri = (new CatalogUri())->resolve($block->getLocation()->getURL()->getString(), $baseUri);
                    $this->load($uri, $depth + 1);
                }
                continue;
            }
            $importsAllowed = false;
            if ($block instanceof AtRuleBlockList && strtolower($block->atRuleName()) === 'media') {
                if ($this->printMedia($block->atRuleArgs())) {
                    $this->blocks($block, $source, $baseUri, $depth, false);
                }
                continue;
            }
            if (!$block instanceof DeclarationBlock) {
                $this->diagnostics->unsupported('css.at-rule', 'Only explicit @import and print/all @media rules are supported.');
                continue;
            }
            $this->rule($block, $source);
        }
    }

    private function load(string $uri, int $depth): void
    {
        if (isset($this->imports[$uri])) {
            $this->diagnostics->unsupported('css.import-cycle', 'Cyclic explicit stylesheet import: ' . $uri);
            return;
        }
        $css = $this->resources->stylesheet($uri);
        $this->budget->css($css);
        $this->imports[$uri] = true;
        try {
            $parsed = $this->parse($css);
            if ($parsed !== null) {
                $this->blocks($parsed, new DeclarationSource($css), $uri, $depth, true);
            }
        } finally {
            unset($this->imports[$uri]);
        }
    }

    private function rule(DeclarationBlock $block, DeclarationSource $source): void
    {
        // Compile the complete selector list before applying any of its declarations.
        $compiled = [];
        $policy = new SelectorPolicy();
        foreach ($block->getSelectors() as $selector) {
            $text = $selector->render(new OutputFormat());
            $this->budget->selector($text, $this->elementCount);
            try {
                $parsed = (new SelectorParser())->parse($text);
                if (count($parsed) !== 1 || !$policy->supports($parsed[0], $this->budget->limits->maxDepth)) {
                    $this->diagnostics->unsupported('css.selector', 'Unsupported selector invalidates its complete list: ' . $text);
                    return;
                }
                $expression = $this->translator->cssToXPath($text);
                $matches = $this->xpath->query($expression);
            } catch (ExceptionInterface $error) {
                $this->diagnostics->unsupported('css.selector', 'Unsupported selector: ' . $text . ': ' . $error->getMessage());
                return;
            }
            $elements = [];
            if ($matches !== false) {
                foreach ($matches as $match) {
                    if ($match instanceof \DOMElement) {
                        $elements[] = $match;
                    }
                }
            }
            $compiled[] = [$elements, $parsed[0]->getSpecificity()];
        }
        $declarations = $this->prepare($block, $source);
        foreach ($compiled as [$elements, $specificity]) {
            $this->apply($declarations, $elements, $specificity, false);
        }
    }

    /** @return list<array{values:array<string,string>,important:bool,order:int}> */
    private function prepare(DeclarationBlock $block, DeclarationSource $source): array
    {
        $result = [];
        foreach ($block->getDeclarations() as $declaration) {
            $value = $source->value($declaration);
            $expanded = $this->declarations->expand($declaration->getPropertyName(), $value);
            $result[] = ['values' => $expanded, 'important' => $declaration->getIsImportant(), 'order' => ++$this->order];
        }
        return $result;
    }

    /**
     * @param list<array{values:array<string,string>,important:bool,order:int}> $declarations
     * @param list<\DOMElement> $elements
     */
    private function apply(array $declarations, array $elements, Specificity $specificity, bool $inline): void
    {
        foreach ($declarations as $declaration) {
            foreach ($declaration['values'] as $property => $propertyValue) {
                $candidate = new CascadedValue($propertyValue, $declaration['important'], $inline, $specificity, $declaration['order']);
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
