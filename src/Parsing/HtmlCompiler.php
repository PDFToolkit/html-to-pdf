<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Parsing;

use Masterminds\HTML5\Parser\Scanner;
use Masterminds\HTML5\Parser\Tokenizer;
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Css\Cascade;
use PDF\HtmlToPdf\Css\StyleComputer;
use PDF\HtmlToPdf\Css\StyleState;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\FormControls;
use PDF\HtmlToPdf\Model\HtmlDocument;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\RadioSnapshotStyle;

/** Stateless facade: each invocation owns its DOM, cascade, budgets and node identities. */
final class HtmlCompiler
{
    public function parse(HtmlSource $source, ConversionOptions $options, Diagnostics $diagnostics): HtmlDocument
    {
        $budget = new ParseBudget($options->limits);
        $budget->input($source->html);
        if ($options->resources->totalBytes() > $options->limits->maxResourceBytes) {
            throw new LimitExceeded('Explicit resource byte budget exhausted.');
        }
        $builder = new BoundedTreeBuilder($budget, $diagnostics);
        (new Tokenizer(new Scanner((new HtmlDoctypeProjection())->project($source->html), 'UTF-8'), $builder))->parse();
        $document = $builder->document();
        if (!$document instanceof \DOMDocument) {
            throw new ConversionException('HTML parser did not produce a document.');
        }
        [$html, $body] = $this->normalize($document, $budget);
        $this->validateTree($html, $options);
        $cascade = new Cascade($document, $budget, $diagnostics, $options->resources);
        $elements = iterator_to_array($document->getElementsByTagName('*'));
        foreach ($elements as $element) {
            $tag = strtolower($element->tagName);
            if ($tag === 'style') {
                if ($element->hasAttribute('type') && !in_array(strtolower(trim($element->getAttribute('type'))), ['', 'text/css'], true)) {
                    $diagnostics->unsupported('html.style-type', 'Only text/css stylesheets are supported.');
                } elseif ($cascade->printMedia($element->getAttribute('media'))) {
                    $cascade->stylesheet($element->textContent, $source->baseUri);
                }
            } elseif ($tag === 'link') {
                if (strtolower(trim($element->getAttribute('rel'))) !== 'stylesheet' || $element->hasAttribute('disabled')) {
                    $diagnostics->unsupported('html.link', 'Only enabled stylesheet link elements are supported.');
                } elseif ($cascade->printMedia($element->getAttribute('media'))) {
                    $cascade->linkedStylesheet($element->getAttribute('href'), $source->baseUri);
                }
            }
            $this->checkElement($element, $diagnostics, $options);
        }
        $cascade->stylesheet($source->stylesheet, $source->baseUri);
        foreach ($elements as $element) {
            if ($element->hasAttribute('style')) {
                $cascade->inline($element, $element->getAttribute('style'));
            }
        }
        $computer = new StyleComputer($diagnostics);
        $htmlStyle = $computer->compute('html', $this->rules($html, $cascade), new StyleState());
        $sequence = 0;
        $root = $this->node($body, $htmlStyle, $htmlStyle->style->fontSize, $cascade, $computer, $diagnostics, $options, $sequence, $htmlStyle->style->display === 'none', $source->baseUri);
        if ($root === null) {
            throw new ConversionException('HTML has no body.');
        }
        if ($htmlStyle->style->display === 'none') {
            $root = new Node($root->id, $root->tag, $root->attributes, $root->children, style: new ComputedStyle(...array_replace(get_object_vars($root->style), ['display' => 'none'])));
        }
        $canvas = $htmlStyle->style->display === 'none' ? null : $htmlStyle->style->background;
        if ($htmlStyle->style->display !== 'none' && $canvas === null && $root->style->background !== null) {
            $canvas = $root->style->background;
            $root = new Node($root->id, $root->tag, $root->attributes, $root->children, style: new ComputedStyle(...array_replace(get_object_vars($root->style), ['background' => null])));
        }
        $titleElement = $document->getElementsByTagName('title')->item(0);
        $title = $titleElement === null ? '' : trim($titleElement->textContent);
        $language = trim($body->getAttribute('lang')) ?: trim($html->getAttribute('lang')) ?: 'en';
        return new HtmlDocument($root, $title, $language, $canvas);
    }

    /** @return array{\DOMElement,\DOMElement} */
    private function normalize(\DOMDocument $document, ParseBudget $budget): array
    {
        $html = $document->documentElement;
        if (!$html instanceof \DOMElement) {
            $budget->node(1);
            $html = $document->createElement('html');
            $document->appendChild($html);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            $budget->node(2);
            $body = $document->createElement('body');
            $children = iterator_to_array($html->childNodes);
            $html->appendChild($body);
            foreach ($children as $child) {
                if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['head', 'title', 'meta', 'link', 'style'], true)) {
                    continue;
                }
                $body->appendChild($child);
            }
        }
        return [$html, $body];
    }

    private function checkElement(\DOMElement $element, Diagnostics $diagnostics, ConversionOptions $options): void
    {
        $tag = strtolower($element->tagName);
        if (!$this->supportedTag($tag) || ($element->namespaceURI !== null && $element->namespaceURI !== '' && $element->namespaceURI !== 'http://www.w3.org/1999/xhtml')) {
            $diagnostics->unsupported('html.tag', 'Unsupported HTML element: ' . $tag);
        }
        if (in_array($tag, ['form', 'input'], true) && $options->formControls !== FormControls::StaticSnapshot) {
            $diagnostics->unsupported('html.form', 'Form controls require the explicit static snapshot option.');
        } elseif ($tag === 'input' && strtolower($element->getAttribute('type')) !== 'radio') {
            $diagnostics->unsupported('html.form-control', 'Only static radio input snapshots are supported.');
        }
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on') || in_array($name, ['srcset', 'sizes', 'align', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'background', 'reversed'], true)) {
                $diagnostics->unsupported('html.attribute', 'Unsupported HTML attribute: ' . $name);
            }
            if ($name === 'role' || str_starts_with($name, 'aria-')) {
                $diagnostics->unsupported('html.aria', 'ARIA roles and properties are not supported: ' . $name);
            }
        }
        if (strtolower(trim($element->getAttribute('hidden'))) === 'until-found') {
            $diagnostics->unsupported('html.hidden-until-found', 'Interactive hidden-until-found content is not supported.');
        }
        if ($element->hasAttribute('dir') && strtolower($element->getAttribute('dir')) !== 'ltr') {
            $diagnostics->unsupported('html.direction', 'Only left-to-right HTML direction is supported.');
        }
        if ($tag === 'meta') {
            if ($element->hasAttribute('http-equiv')) {
                $diagnostics->unsupported('html.meta', 'HTTP-equivalent metadata is not executed.');
            }
            if ($element->hasAttribute('charset') && !in_array(strtolower($element->getAttribute('charset')), ['utf-8', 'utf8'], true)) {
                $diagnostics->unsupported('html.charset', 'The conversion source must use UTF-8.');
            }
        }
        foreach (['rowspan', 'colspan'] as $span) {
            if ($element->hasAttribute($span) && trim($element->getAttribute($span)) !== '1') {
                $diagnostics->unsupported('html.table-span', 'Table row and column spans are not supported.');
            }
        }
        if ($element->hasAttribute('scope') && ($tag !== 'th' || !in_array(strtolower($element->getAttribute('scope')), ['row', 'col'], true))) {
            $diagnostics->unsupported('html.table-scope', 'Only row and col scope on table header cells is supported.');
        }
    }

    private function supportedTag(string $tag): bool
    {
        return in_array($tag, ['html', 'head', 'body', 'dl', 'dt', 'dd', 'form', 'input', 'title', 'meta', 'style', 'link', 'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'address', 'p', 'span', 'strong', 'b', 'em', 'i', 'u', 'code', 'samp', 'kbd', 'pre', 'blockquote', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'img', 'a'], true);
    }

    private function node(\DOMNode $dom, StyleState $parent, float $rootSize, Cascade $cascade, StyleComputer $computer, Diagnostics $diagnostics, ConversionOptions $options, int &$sequence, bool $hidden = false, ?string $baseUri = null): ?Node
    {
        if ($dom instanceof \DOMText) {
            return new Node('node-' . ++$sequence, '#text', text: $dom->textContent, style: $parent->style);
        }
        if (!$dom instanceof \DOMElement) {
            return null;
        }
        $tag = strtolower($dom->tagName);
        if (in_array($tag, ['head', 'title', 'meta', 'style', 'link', 'script', 'template', 'iframe', 'object', 'embed', 'canvas', 'svg', 'math', 'textarea', 'select', 'button', 'audio', 'video'], true)) {
            return null;
        }
        if ($tag === 'input' && ($options->formControls !== FormControls::StaticSnapshot || strtolower($dom->getAttribute('type')) !== 'radio')) {
            return null;
        }
        $id = 'node-' . ++$sequence;
        $rules = $this->rules($dom, $cascade);
        foreach (['width', 'height'] as $dimension) {
            if ($dom->hasAttribute($dimension) && !isset($rules[$dimension])) {
                if (!in_array($tag, ['img', 'table', 'td', 'th'], true)) {
                    $diagnostics->unsupported('html.dimension', 'Dimension attributes are supported on images and tables only.', $id);
                    continue;
                }
                $raw = trim($dom->getAttribute($dimension));
                if (preg_match('/^[0-9]+(?:\.[0-9]+)?%?$/D', $raw) === 1) {
                    $rules[$dimension] = str_ends_with($raw, '%') ? $raw : $raw . 'px';
                } else {
                    $diagnostics->unsupported('html.dimension', 'Unsupported HTML ' . $dimension . ' attribute.', $id);
                }
            }
        }
        $style = $computer->compute($tag, $rules, $parent, $rootSize);
        $hidden = $hidden || $style->style->display === 'none';
        if ($tag === 'input' && !$hidden) {
            try {
                (new RadioSnapshotStyle())->validate($style->style);
            } catch (UnsupportedFeature $error) {
                $diagnostics->unsupported('html.form-style', $error->getMessage(), $id);
                return null;
            }
        }
        $attributes = [];
        foreach (['id', 'class', 'lang', 'title', 'alt', 'href', 'src', 'width', 'height', 'start', 'value', 'scope', 'rowspan', 'colspan', 'type', 'name', 'checked', 'disabled'] as $name) {
            if ($dom->hasAttribute($name)) {
                $attributes[$name] = $dom->getAttribute($name);
            }
        }
        if ($tag === 'a' && isset($attributes['href'])) {
            try {
                $attributes['href'] = (new LinkTarget())->resolve($attributes['href'], $baseUri);
            } catch (ConversionException $error) {
                $diagnostics->unsupported('html.link-target', $error->getMessage(), $id);
                unset($attributes['href']);
            }
        }
        if ($tag === 'input') {
            $attributes['type'] = 'radio';
        }
        if ($tag === 'img' && !$hidden) {
            $options->resources->image($attributes['src'] ?? '');
        }
        if (!$this->supportedTag($tag)) {
            $tag = 'span';
        }
        $children = [];
        foreach ($dom->childNodes as $child) {
            $node = $this->node($child, $style, $rootSize, $cascade, $computer, $diagnostics, $options, $sequence, $hidden, $baseUri);
            if ($node !== null) {
                $children[] = $node;
            }
        }
        return new Node($id, $tag, $attributes, $children, style: $style->style);
    }

    /** @return array<string,string> */
    private function rules(\DOMElement $element, Cascade $cascade): array
    {
        $rules = $cascade->forElement($element);
        // HTML's hidden state is a UA display rule, overridable by author CSS.
        if ($element->hasAttribute('hidden') && !isset($rules['display'])) {
            $rules['display'] = 'none';
        }
        return $rules;
    }

    private function validateTree(\DOMElement $root, ConversionOptions $options): void
    {
        $stack = [[$root, 1]];
        $count = 0;
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if (++$count > $options->limits->maxNodes || $depth > $options->limits->maxDepth) {
                throw new LimitExceeded('Normalized HTML tree exceeds node or depth limits.');
            }
            foreach ($node->childNodes as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
    }
}
