<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Model\HtmlDocument;

/** Stateless entry point. Every run receives fresh shaping, structure and pagination state. */
final readonly class LayoutEngine
{
    public function layout(HtmlDocument $document, ConversionOptions $options, Diagnostics $diagnostics): PagedDocument
    {
        $pending = [[$document->root, 1, false]];
        $ids = [];
        $characters = 0;
        $hasFloats = false;
        while ($pending !== []) {
            [$node, $depth, $hidden] = array_pop($pending);
            if (isset($ids[$node->id])) {
                throw new \InvalidArgumentException('Source node identifiers must be unique.');
            }
            $ids[$node->id] = true;
            $characters += mb_strlen($node->text, 'UTF-8');
            if (count($ids) > $options->limits->maxNodes || $depth > $options->limits->maxDepth || $characters > $options->limits->maxTextCharacters) {
                throw new LimitExceeded('Source model exceeds layout limits.');
            }
            $s = $node->style;
            $hidden = $hidden || $s->display === 'none';
            $hasFloats = $hasFloats || (!$hidden && !$node->isText() && $s->float !== 'none');
            if (!in_array($s->display, ['none', 'block', 'inline', 'list-item', 'table', 'table-row', 'table-cell', 'table-header-group', 'table-row-group', 'table-footer-group', 'table-caption'], true)
                || !in_array($s->whiteSpace, ['normal', 'nowrap', 'pre', 'pre-wrap'], true)
                || !in_array($s->textAlign, ['left', 'center', 'right'], true)
                || !in_array($s->listStyleType, ['none', 'disc', 'circle', 'square', 'decimal'], true)
                || !in_array($s->verticalAlign, ['top', 'middle', 'bottom'], true)
                || !in_array($s->breakBefore, ['auto', 'page', 'always'], true)
                || !in_array($s->breakAfter, ['auto', 'page', 'always'], true)) {
                throw new UnsupportedFeature('The computed style requests an unsupported layout mode.');
            }
            if (!in_array($s->float, ['none', 'left', 'right'], true) || !in_array($s->clear, ['none', 'left', 'right', 'both'], true)) {
                throw new UnsupportedFeature('The computed style requests an unsupported float or clearance mode.');
            }
            if (!$node->isText() && $s->display !== 'none') {
                $tableDisplays = ['table' => 'table', 'tr' => 'table-row', 'td' => 'table-cell', 'th' => 'table-cell', 'thead' => 'table-header-group', 'tbody' => 'table-row-group', 'tfoot' => 'table-footer-group', 'caption' => 'table-caption'];
                if ((isset($tableDisplays[$node->tag]) && $s->display !== $tableDisplays[$node->tag])
                    || (in_array($s->display, array_values($tableDisplays), true) && ($tableDisplays[$node->tag] ?? null) !== $s->display)
                    || ($s->display === 'list-item' && $node->tag !== 'li')
                    || ($node->tag === 'li' && !in_array($s->display, ['list-item', 'block'], true))) {
                    throw new UnsupportedFeature('Changing table or list display roles is outside this layout profile.');
                }
            }
            if (!is_finite($s->fontSize) || $s->fontSize <= 0 || !is_finite($s->lineHeight) || $s->lineHeight <= 0 || !is_finite($s->borderWidth) || $s->borderWidth < 0 || $s->widows < 1 || $s->orphans < 1
                || min($s->padding->top, $s->padding->right, $s->padding->bottom, $s->padding->left, $s->margin->top, $s->margin->right, $s->margin->bottom, $s->margin->left) < 0) {
                throw new UnsupportedFeature('Only finite positive font geometry and non-negative box edges are supported.');
            }
            if (count($ids) + count($pending) + count($node->children) > $options->limits->maxNodes) {
                throw new LimitExceeded('Source model node queue exceeds layout limits.');
            }
            foreach (array_reverse($node->children) as $child) {
                $pending[] = [$child, $depth + 1, $hidden];
            }
        }
        if ($options->resources->totalBytes() > $options->limits->maxResourceBytes) {
            throw new LimitExceeded('Explicit resource byte budget exhausted.');
        }
        foreach ($options->resources->images() as $image) {
            if ($image->width * $image->height > $options->limits->maxImagePixels) {
                throw new LimitExceeded('Explicit image pixel budget exhausted.');
            }
        }
        $semantics = new SemanticTree();
        $root = $semantics->add('Document', language: $document->language);
        if ($document->root->style->display !== 'none' && $document->root->style->float !== 'none') {
            throw new UnsupportedFeature('Floating the document root is outside this layout profile.');
        }
        $floats = $hasFloats ? new FloatContext($options->limits->maxCommands * 8) : null;
        $flow = (new FlowBuilder($options, $diagnostics, $semantics))->build($document->root, 0, $options->page->contentWidth(), $root, true, floats: $floats);
        if ($hasFloats) {
            $combined = (new FlowAssembly())->combine($flow);
            $combined->height = $combined->extent();
            if ($combined->height > $options->page->contentHeight() + 0.001) {
                throw new UnsupportedFeature('A document containing floats must fit within one page in this layout profile; float fragmentation is unsupported.');
            }
            $flow = [$combined];
        }
        $pages = (new Paginator($options, $diagnostics))->paginate($flow);
        if ($document->canvasBackground !== null) {
            $commandCount = count($pages) + array_sum(array_map(static fn(LayoutPage $page): int => count($page->commands), $pages));
            if ($commandCount > $options->limits->maxCommands) {
                throw new LimitExceeded('Canvas backgrounds exceed the drawing command budget.');
            }
            $pages = array_map(static fn(LayoutPage $page): LayoutPage => new LayoutPage($page->width, $page->height, [new Rectangle(0, 0, $page->width, $page->height, $document->canvasBackground), ...$page->commands]), $pages);
        }

        return new PagedDocument($pages, $semantics->all(), $document->title, $document->language);
    }
}
