<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\FormControls;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Style\Borders;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\RadioSnapshotStyle;

/** Builds bounded, page-independent flow slices. No shared caches or external resources. */
final class FlowBuilder
{
    private readonly InlineFormatter $inline;
    private readonly CommandTranslation $translation;
    private readonly MarginCollapser $margins;
    private int $paragraph = 0;
    private int $items = 0;
    private int $commands = 0;

    public function __construct(private readonly ConversionOptions $options, private readonly Diagnostics $diagnostics, private readonly SemanticTree $semantics)
    {
        $this->inline = new InlineFormatter($options, $diagnostics);
        $this->translation = new CommandTranslation();
        $this->margins = new MarginCollapser();
    }

    /**
     * @return list<FlowItem>
     */
    public function build(Node $node, float $x, float $available, string $parent, bool $root = false, ?string $marker = null, ?string $uri = null, ?FloatContext $floats = null, float $top = 0, bool $floating = false): array
    {
        $s = $node->style;
        if ($s->display === 'none') {
            return [];
        }
        if ($node->tag === 'input') {
            $this->validateRadio($node);
            throw new UnsupportedFeature('Radio snapshots require an inline formatting context.');
        }
        $floatCheckpoint = $floats?->checkpoint() ?? 0;
        $uri = $node->tag === 'a' ? $node->attribute('href') : $uri;
        if ($node->tag === 'img' && $uri !== null) {
            throw new UnsupportedFeature('Linked images are not supported by the initial annotation profile.');
        }
        $semantic = $root || ($node->tag === 'img' && $node->attribute('alt') === '') ? $parent : $this->semantics->forNode($node, $parent);
        $label = null;
        if ($node->tag === 'li') {
            if ($marker !== null) {
                $label = $this->semantics->add('Lbl', $semantic);
            }
            $semantic = $this->semantics->add('LBody', $semantic);
        }
        $outerX = $x + $s->margin->left;
        $borders = $s->borderEdges();
        $edges = $borders->widths();
        $extras = $s->padding->horizontal() + $edges->horizontal();
        $blockImage = $node->tag === 'img' ? $this->image($node, $available, $semantic) : null;
        $width = $blockImage->imageWidth ?? $s->width?->resolve($available) ?? ($available - $s->margin->horizontal() - $extras);
        if ($width <= 0 || $width + $extras + $s->margin->horizontal() > $available + 0.001) {
            throw new UnsupportedFeature('A block box exceeds its available width.');
        }
        $innerX = $outerX + $edges->left + $s->padding->left;
        $markerWidth = $marker === null ? 0.0 : max(18.0, $this->inline->advance($marker . ' ', $s));
        if ($node->tag === 'table') {
            if ($floats !== null) {
                throw new UnsupportedFeature('Combining table formatting and floats is outside this layout profile.');
            }
            $flow = $this->table($node, $innerX, $width, $semantic, $uri);
        } elseif ($node->tag === 'hr') {
            $height = $s->height?->resolve($this->options->page->contentHeight()) ?? max(1.0, $edges->top);
            $flow = [new FlowItem($height, [new Rectangle($innerX, 0, $width, $height, $s->color)])];
        } elseif ($node->tag === 'img') {
            $flow = [new FlowItem($blockImage->imageHeight, [new Image($innerX, 0, $blockImage->imageWidth, $blockImage->imageHeight, $blockImage->image, $blockImage->semanticId)])];
        } else {
            // A marker narrows the line, not the list item's percentage indentation basis.
            $flow = $this->children($node, $innerX + $markerWidth, $width - $markerWidth, $semantic, $uri, $floats, $top + $s->margin->top + $edges->top + $s->padding->top, $marker === null ? null : $width);
        }
        if ($marker !== null && $label !== null) {
            if ($flow === []) {
                $flow = $this->inline->format([new InlinePiece('', $s, $label, lineBreak: true)], $innerX + $markerWidth, $width - $markerWidth, $s, 'paragraph-' . ++$this->paragraph, indentBasis: $width);
            }
            $first = $flow[0];
            foreach ($flow as $candidate) {
                foreach ($candidate->commands as $command) {
                    if ($command instanceof Text) {
                        $first = $candidate;
                        break 2;
                    }
                }
            }
            $font = $this->inline->face($s);
            $baseline = $font->metrics($s->fontSize)->ascent;
            foreach ($first->commands as $command) {
                if ($command instanceof Text) {
                    $baseline = $command->y;
                    break;
                }
            }
            array_unshift($first->commands, ...$this->inline->texts($marker, $s, $innerX, $baseline, $label, $uri)[0]);
        }
        $flow = $this->margins->collapse($flow);
        $topMargin = new FlowItem($s->margin->top);
        $bottomMargin = new FlowItem($s->margin->bottom);
        $topMargin->margin = $bottomMargin->margin = !$floating;
        $normalBlock = !$floating && in_array($s->display, ['block', 'list-item'], true) && !in_array($node->tag, ['img', 'hr'], true);
        $firstInFlow = $this->margins->firstInFlow($flow);
        if ($normalBlock && $s->padding->top + $edges->top === 0.0 && $firstInFlow?->margin && !$firstInFlow->breakBefore) {
            if ($floats !== null) {
                throw new UnsupportedFeature('Parent-child margin collapse inside float contexts is outside this layout profile. Add explicit padding or border to separate the margins.');
            }
            $childMargin = array_shift($flow);
            $topMargin->height = max($topMargin->height, $childMargin->height);
            $topMargin->avoidGroups = $childMargin->avoidGroups;
        }
        if ($normalBlock && $s->height === null && $s->padding->bottom + $edges->bottom === 0.0 && $flow !== [] && $flow[array_key_last($flow)]->margin && !$flow[array_key_last($flow)]->breakAfter) {
            $childMargin = array_pop($flow);
            $bottomMargin->height = max($bottomMargin->height, $childMargin->height);
            $bottomMargin->avoidGroups = $childMargin->avoidGroups;
        }
        // Remaining margins are internal to this box and cannot escape through an ancestor.
        foreach ($flow as $item) {
            $item->margin = false;
        }
        $height = array_sum(array_map(static fn(FlowItem $item): float => $item->height, $flow));
        if ($floating && $floats !== null && $s->height === null) {
            $contentTop = $top + $s->margin->top + $edges->top + $s->padding->top;
            $floatHeight = $floats->clear('both', $contentTop) - $contentTop;
            if ($floatHeight > $height) {
                $flow[] = new FlowItem($floatHeight - $height);
                $height = $floatHeight;
            }
        }
        if ($s->height !== null && $node->tag !== 'img' && $node->tag !== 'hr') {
            if ($s->height->percentage) {
                throw new UnsupportedFeature('Percentage block heights require a definite containing height and are outside this profile.');
            }
            if ($height > $s->height->value + 0.001) {
                if ($floats === null) {
                    throw new UnsupportedFeature('Content exceeds an explicit block height.');
                }
                $flow = [(new FlowAssembly())->combine($flow, $s->height->value)];
            }
            if ($s->height->value > $height) {
                $flow[] = new FlowItem($s->height->value - $height);
            }
        }
        if ($flow === []) {
            $flow[] = new FlowItem(0);
        }
        // Keep top/bottom padding and borders attached to actual content. A page break
        // cannot strand a decoration-only slice before the first or after the last line.
        $topInset = $s->padding->top + $edges->top;
        $bottomInset = $s->padding->bottom + $edges->bottom;
        $flow[0]->height += $topInset;
        if ($flow[0]->paintHeight !== null) {
            $flow[0]->paintHeight += $topInset;
        }
        foreach ($flow[0]->commands as $index => $command) {
            $flow[0]->commands[$index] = $this->translation->move($command, 0, $topInset);
        }
        $flow[array_key_last($flow)]->height += $bottomInset;
        if ($topInset > 0) {
            foreach ($flow as $item) {
                $item->avoidGroups[] = 'box-start-' . $node->id;
                if ($item->commands !== []) {
                    break;
                }
            }
        }
        if ($bottomInset > 0 && $s->height === null) {
            foreach (array_reverse($flow) as $item) {
                $item->avoidGroups[] = 'box-end-' . $node->id;
                if ($item->commands !== []) {
                    break;
                }
            }
        }
        $boxWidth = $width + $extras;
        $boxHeight = array_sum(array_map(static fn(FlowItem $item): float => $item->height, $flow));
        if (!$floating && $floats !== null && ($s->background !== null || $borders->hasWidth())
            && $floats->overlapsBefore($floatCheckpoint, $outerX, $top + $s->margin->top, $boxWidth, $boxHeight)) {
            throw new UnsupportedFeature('Painting a normal block background or border beneath earlier floats is outside this layout profile.');
        }
        $offset = 0.0;
        foreach ($flow as $index => $item) {
            $decorations = [];
            $decorationHeight = $floats === null ? $item->height : ($index === 0 ? $boxHeight : 0.0);
            if ($s->background !== null && $decorationHeight > 0) {
                $decorations[] = new Rectangle($outerX, 0, $boxWidth, $decorationHeight, $s->background, semanticId: $semantic);
            }
            if ($borders->hasWidth() && $decorationHeight > 0) {
                $decorations[] = new BorderBox($outerX, 0, $boxWidth, $decorationHeight, $borders, $offset, $boxHeight, $semantic);
            }
            if ($decorations !== [] && $floats !== null) {
                $item->paintHeight = max($item->extent(), $decorationHeight);
            }
            $item->commands = [...$decorations, ...$item->commands];
            $offset += $item->height;
        }
        if ($topMargin->height > 0) {
            array_unshift($flow, $topMargin);
        }
        if ($bottomMargin->height > 0) {
            $flow[] = $bottomMargin;
        }
        $flow = $this->margins->collapse($flow);
        if ($flow === []) {
            $flow[] = new FlowItem(0);
        }
        $flow[0]->breakBefore = $flow[0]->breakBefore || in_array($s->breakBefore, ['page', 'always'], true);
        $flow[array_key_last($flow)]->breakAfter = $flow[array_key_last($flow)]->breakAfter || in_array($s->breakAfter, ['page', 'always'], true);
        if ($s->breakInsideAvoid) {
            foreach ($flow as $item) {
                $item->avoidGroups[] = $node->id;
            }
        }
        $this->account($flow);

        return $flow;
    }

    /**
     * @return list<FlowItem>
     */
    private function children(Node $node, float $x, float $width, string $semantic, ?string $uri, ?FloatContext $floats = null, float $top = 0, ?float $indentBasis = null): array
    {
        if ($width <= 0) {
            throw new UnsupportedFeature('List indentation leaves no available width.');
        }
        $flow = [];
        $inlineNodes = [];
        $firstLine = true;
        $listCounter = null;
        foreach ((new InlineBlockSplitter())->split($node->children) as $child) {
            if ($child->style->display === 'none') {
                continue;
            }
            if ($child->isText() || ($child->style->float === 'none' && $child->style->display === 'inline') || $child->tag === 'br') {
                $inlineNodes[] = $child;
                continue;
            }
            $inlineFlow = $this->flush($inlineNodes, $x, $width, $node->style, $semantic, $uri, $floats, $top + $this->height($flow), $firstLine, $indentBasis);
            if ($child->style->float !== 'none' && $inlineFlow !== []) {
                throw new UnsupportedFeature('Inserting a float after inline content in the same formatting context is outside this layout profile.');
            }
            array_push($flow, ...$inlineFlow);
            if ($inlineFlow !== []) {
                $firstLine = false;
            }
            $inlineNodes = [];
            $marker = null;
            if ($child->tag === 'li' && $child->style->display === 'list-item') {
                $listCounter ??= new ListCounter($node->attribute('start'));
                $listIndex = $listCounter->next($child->attribute('value'));
                $marker = match ($child->style->listStyleType) {
                    'none' => null,
                    'decimal' => $listIndex . '.',
                    'circle' => '○',
                    'square' => '▪',
                    default => '•',
                };
            }
            $position = $top + $this->height($flow);
            if ($floats !== null && $child->style->float !== 'none') {
                $flow[] = $this->floating($child, $x, $width, $position, $semantic, $marker, $uri, $floats);
                continue;
            }
            if ($floats !== null) {
                $cursor = $position;
                $last = $flow === [] ? null : $flow[array_key_last($flow)];
                if ($last?->margin) {
                    $position -= min($last->height, $child->style->margin->top);
                }
                $borderTop = $position + $child->style->margin->top;
                $cleared = $floats->clear($child->style->clear, $borderTop);
                if ($cleared > $borderTop + 0.001) {
                    $gap = max(0.0, $cleared - $cursor - $child->style->margin->top);
                    $clearance = new FlowItem($gap);
                    $clearance->marginBoundary = true;
                    $flow[] = $clearance;
                    $position = $cursor + $gap;
                }
            }
            $firstLine = false;
            $childFlow = $this->build($child, $x, $width, $semantic, marker: $marker, uri: $uri, floats: $floats, top: $position);
            array_push($flow, ...$childFlow);
            $flow = $this->margins->collapse($flow);
        }

        return $this->margins->collapse([...$flow, ...$this->flush($inlineNodes, $x, $width, $node->style, $semantic, $uri, $floats, $top + $this->height($flow), $firstLine, $indentBasis)]);
    }

    private function floating(Node $node, float $x, float $width, float $top, string $semantic, ?string $marker, ?string $uri, FloatContext $floats): FlowItem
    {
        if ($node->style->width === null) {
            throw new UnsupportedFeature('Floated boxes require an explicit width in this layout profile.');
        }
        if (!in_array($node->style->display, ['block', 'inline', 'list-item'], true) || in_array($node->tag, ['table', 'tr', 'td', 'th'], true)) {
            throw new UnsupportedFeature('Floating table layout is outside this layout profile.');
        }
        $boxWidth = $node->style->width->resolve($width) + $node->style->padding->horizontal() + $node->style->borderEdges()->widths()->horizontal() + $node->style->margin->horizontal();
        $nested = $floats->nested();
        $box = (new FlowAssembly())->combine($this->build($node, 0, $width, $semantic, marker: $marker, uri: $uri, floats: $nested, floating: true));
        $placement = $floats->place($node->style->float, $boxWidth, $box->height, $floats->clear($node->style->clear, $top), $x, $width);
        $commands = [];
        foreach ($box->commands as $command) {
            $commands[] = $this->translation->move($command, $placement->x, $placement->top - $top);
        }
        $item = new FlowItem(0, $commands);
        $item->outOfFlow = true;
        $item->paintHeight = $placement->top - $top + $box->extent();
        $item->breakBefore = $box->breakBefore;
        $item->breakAfter = $box->breakAfter;

        return $item;
    }

    /** @param list<FlowItem> $flow */
    private function height(array $flow): float
    {
        return array_sum(array_map(static fn(FlowItem $item): float => $item->height, $flow));
    }

    /** @param list<Node> $nodes
     * @return list<FlowItem>
     */
    private function flush(array $nodes, float $x, float $width, ComputedStyle $style, string $semantic, ?string $uri, ?FloatContext $floats = null, float $top = 0, bool $firstLine = true, ?float $indentBasis = null): array
    {
        if ($nodes === []) {
            return [];
        }
        $pieces = [];
        foreach ($nodes as $node) {
            $pieces = [...$pieces, ...$this->collect($node, $semantic, $width, $uri)];
        }

        return $this->inline->format($pieces, $x, $width, $style, 'paragraph-' . ++$this->paragraph, $floats === null ? null : new LineExclusions($floats, $x, $width), $top, $firstLine, $indentBasis);
    }

    /**
     * @return list<InlinePiece>
     */
    private function collect(Node $node, string $parent, float $width, ?string $uri = null): array
    {
        if ($node->style->display === 'none') {
            return [];
        }
        if ($node->isText()) {
            return $node->text === '' ? [] : [new InlinePiece($node->text, $node->style, $this->semantics->add('Span', $parent), $uri)];
        }
        $semantic = $node->tag === 'img' && $node->attribute('alt') === '' ? $parent : $this->semantics->forNode($node, $parent);
        if ($node->tag === 'br') {
            return [new InlinePiece('', $node->style, $semantic, lineBreak: true)];
        }
        if ($node->tag === 'input') {
            $this->validateRadio($node);
            return [new InlinePiece('', $node->style, $semantic, radio: new RadioButton(0, 0, 9.75, 9.75, $node->attribute('checked') !== null, $node->attribute('disabled') !== null, $semantic))];
        }
        if ($node->tag === 'img') {
            if ($uri !== null) {
                throw new UnsupportedFeature('Linked images are not supported by the initial annotation profile.');
            }
            if ($node->style->padding->horizontal() !== 0.0 || $node->style->padding->vertical() !== 0.0 || $node->style->borderEdges()->hasWidth() || $node->style->margin->horizontal() !== 0.0) {
                throw new UnsupportedFeature('Inline image box decorations require display:block in this layout profile.');
            }
            return [$this->image($node, $width, $semantic)];
        }
        if ($node->style->display !== 'inline') {
            throw new UnsupportedFeature('A block nested inside an inline formatting context is not supported.');
        }
        if ($node->style->margin->horizontal() !== 0.0 || $node->style->padding->horizontal() !== 0.0 || $node->style->padding->vertical() !== 0.0 || $node->style->borderEdges()->hasWidth()) {
            throw new UnsupportedFeature('Inline margins, padding and borders are outside this layout profile.');
        }
        $uri = $node->tag === 'a' ? $node->attribute('href') : $uri;
        $pieces = [];
        foreach ($node->children as $child) {
            $pieces = [...$pieces, ...$this->collect($child, $semantic, $width, $uri)];
        }

        return $pieces;
    }

    private function validateRadio(Node $node): void
    {
        if ($this->options->formControls !== FormControls::StaticSnapshot || $node->attribute('type') !== 'radio') {
            throw new UnsupportedFeature('Only explicitly enabled static radio snapshots are supported.');
        }
        (new RadioSnapshotStyle())->validate($node->style);
    }

    private function image(Node $node, float $basis, string $semantic): InlinePiece
    {
        $source = $node->attribute('src') ?? throw new UnsupportedFeature('Images require an explicit source resource.');
        $asset = $this->options->resources->image($source);
        $width = $node->style->width?->resolve($basis);
        if ($node->style->height?->percentage) {
            throw new UnsupportedFeature('Percentage image heights require a definite containing height.');
        }
        $height = $node->style->height?->value;
        if ($width === null && $height === null) {
            $width = $asset->width * 0.75;
            $height = $asset->height * 0.75;
        } elseif ($width === null) {
            $width = $height * $asset->width / $asset->height;
        } elseif ($height === null) {
            $height = $width * $asset->height / $asset->width;
        }
        if ($width <= 0 || $height <= 0 || $height > $this->options->page->contentHeight()) {
            throw new UnsupportedFeature('An image must have positive dimensions and fit within a page.');
        }
        if ($node->attribute('alt') === null) {
            throw new UnsupportedFeature('Images require alt text, or an explicit empty alt for decoration.');
        }

        return new InlinePiece('', $node->style, $node->attribute('alt') === '' ? null : $semantic, image: $asset, imageWidth: $width, imageHeight: $height);
    }

    /**
     * @return list<FlowItem>
     */
    private function table(Node $node, float $x, float $width, string $semantic, ?string $uri): array
    {
        if ($node->style->tableLayout !== 'fixed') {
            $this->diagnostics->unsupported('layout.table-auto', 'Automatic table sizing is unsupported; table-layout:fixed is required. Report policy falls back to fixed columns.', $node->id);
        }
        $rows = [];
        $headers = [];
        $prefix = [];
        foreach ($node->children as $child) {
            if ($child->style->display === 'none' || ($child->isText() && trim($child->text) === '')) {
                continue;
            }
            if ($child->tag === 'caption') {
                $prefix = [...$prefix, ...$this->build($child, $x, $width, $semantic, uri: $uri)];
            } elseif ($child->tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($child->tag, ['thead', 'tbody', 'tfoot'], true)) {
                if ($child->tag === 'tfoot') {
                    throw new UnsupportedFeature('Repeating table footers are outside the initial table profile.');
                }
                if ($child->style->background !== null) {
                    throw new UnsupportedFeature('Table row-group backgrounds are unsupported; apply the background to rows or cells.');
                }
                foreach ($child->children as $row) {
                    if ($row->style->display === 'none') {
                        continue;
                    }
                    if ($row->isText() && trim($row->text) === '') {
                        continue;
                    }
                    if ($row->tag !== 'tr') {
                        throw new UnsupportedFeature('Table row groups may only contain rows.');
                    }
                    if ($child->tag === 'thead') {
                        $headers[] = $row;
                    } else {
                        $rows[] = $row;
                    }
                }
            } else {
                throw new UnsupportedFeature('Only captions and rectangular rows are supported inside tables.');
            }
        }
        $all = [...$headers, ...$rows];
        if ($all === []) {
            return $prefix;
        }
        $firstCells = $this->cells($all[0]);
        $count = count($firstCells);
        if ($count === 0) {
            throw new UnsupportedFeature('A table row requires cells.');
        }
        $widths = [];
        $specified = 0.0;
        $automatic = 0;
        foreach ($firstCells as $cell) {
            $cellWidth = $cell->style->width?->resolve($width);
            $widths[] = $cellWidth;
            $specified += $cellWidth ?? 0;
            $automatic += $cellWidth === null ? 1 : 0;
        }
        if ($specified > $width + 0.001 || ($automatic > 0 && $specified >= $width)) {
            throw new UnsupportedFeature('Specified table column widths leave insufficient space.');
        }
        if ($automatic === 0 && abs($specified - $width) > 0.001) {
            throw new UnsupportedFeature('Explicit table columns must add up to the table content width.');
        }
        foreach ($widths as &$columnWidth) {
            $columnWidth ??= ($width - $specified) / $automatic;
        }
        unset($columnWidth);
        $builtHeaders = [];
        $flow = $prefix;
        foreach ($all as $rowIndex => $row) {
            $cells = $this->cells($row);
            if (count($cells) !== $count) {
                throw new UnsupportedFeature('Table rows must have the same number of unmerged cells.');
            }
            $rowSemantic = $this->semantics->forNode($row, $semantic);
            $cellFlows = [];
            $height = 0.0;
            $cellX = $x;
            foreach ($cells as $index => $cell) {
                if ($cell->style->margin->horizontal() !== 0.0 || $cell->style->margin->vertical() !== 0.0) {
                    throw new UnsupportedFeature('Table cell margins are not supported. Use padding.');
                }
                if ($cell->style->width !== null && abs($cell->style->width->resolve($width) - $widths[$index]) > 0.001) {
                    throw new UnsupportedFeature('Conflicting column widths across table rows are not supported.');
                }
                // Column allocation is the border box; a cell's width declaration selected it above.
                $styleValues = get_object_vars($cell->style);
                $styleValues['width'] = null;
                $styleValues['background'] = null;
                $styleValues['borderWidth'] = 0;
                $styleValues['borders'] = new Borders();
                $padding = $cell->style->padding;
                $border = $cell->style->borderEdges()->widths();
                $styleValues['padding'] = new Edges($padding->top + $border->top, $padding->right + $border->right, $padding->bottom + $border->bottom, $padding->left + $border->left);
                $cellCopy = new Node($cell->id, $cell->tag, $cell->attributes, $cell->children, $cell->text, new ComputedStyle(...$styleValues));
                $cellFlow = $this->build($cellCopy, $cellX, $widths[$index], $rowSemantic, uri: $uri);
                foreach ($cellFlow as $item) {
                    if ($item->breakBefore || $item->breakAfter || $item->table !== null) {
                        throw new UnsupportedFeature('Page breaks and nested tables inside indivisible table rows are unsupported.');
                    }
                }
                $cellHeight = array_sum(array_map(static fn(FlowItem $item): float => $item->height, $cellFlow));
                $cellFlows[] = [$cellFlow, $cellHeight, $cell->style, $cellX, $widths[$index]];
                $height = max($height, $cellHeight);
                $cellX += $widths[$index];
            }
            if ($row->style->height !== null) {
                if ($row->style->height->percentage) {
                    throw new UnsupportedFeature('Percentage table row heights are not supported.');
                }
                $height = max($height, $row->style->height->value);
            }
            if ($height > $this->options->page->contentHeight()) {
                throw new UnsupportedFeature('A table row is taller than a page; row splitting is not supported.');
            }
            $commands = $row->style->background === null ? [] : [new Rectangle($x, 0, $width, $height, $row->style->background)];
            foreach ($cellFlows as [$cellFlow, $cellHeight, $cellStyle, $cellX, $cellWidth]) {
                if ($cellStyle->background !== null) {
                    $commands[] = new Rectangle($cellX, 0, $cellWidth, $height, $cellStyle->background);
                }
                if ($cellStyle->borderEdges()->hasWidth()) {
                    $commands[] = new BorderBox($cellX, 0, $cellWidth, $height, $cellStyle->borderEdges(), 0, $height);
                }
                $offset = match ($cellStyle->verticalAlign) {
                    'middle' => ($height - $cellHeight) / 2,
                    'bottom' => $height - $cellHeight,
                    default => 0,
                };
                foreach ($cellFlow as $item) {
                    foreach ($item->commands as $command) {
                        $commands[] = $this->translation->move($command, 0, $offset);
                    }
                    $offset += $item->height;
                }
            }
            $item = new FlowItem($height, $commands);
            $item->table = $node->id;
            $item->breakBefore = in_array($row->style->breakBefore, ['page', 'always'], true);
            $item->breakAfter = in_array($row->style->breakAfter, ['page', 'always'], true);
            if ($rowIndex < count($headers)) {
                $builtHeaders[] = $item;
            } else {
                $item->headers = $builtHeaders;
                if ($height + array_sum(array_map(static fn(FlowItem $header): float => $header->height, $builtHeaders)) > $this->options->page->contentHeight() + 0.001) {
                    throw new UnsupportedFeature('The repeated table header and one row must fit together on a page.');
                }
            }
            $flow[] = $item;
        }
        // Keep the first table header with the first body row.
        $keepCount = min(count($all), count($headers) + 1);
        for ($index = count($prefix); $index < count($prefix) + $keepCount; ++$index) {
            $flow[$index]->avoidGroups[] = 'table-start-' . $node->id;
        }

        return $flow;
    }

    /** @return list<Node> */
    private function cells(Node $row): array
    {
        $cells = [];
        foreach ($row->children as $cell) {
            if ($cell->style->display === 'none' || ($cell->isText() && trim($cell->text) === '')) {
                continue;
            }
            if (!in_array($cell->tag, ['th', 'td'], true) || (int) ($cell->attribute('colspan') ?? '1') !== 1 || (int) ($cell->attribute('rowspan') ?? '1') !== 1) {
                throw new UnsupportedFeature('Only rectangular table rows without merged cells are supported.');
            }
            $cells[] = $cell;
        }

        return $cells;
    }

    /** @param list<FlowItem> $flow */
    private function account(array $flow): void
    {
        // Counting nested observations as work also bounds copying and decoration expansion.
        $this->items += count($flow);
        foreach ($flow as $item) {
            $this->commands += count($item->commands);
        }
        if ($this->items > $this->options->limits->maxCommands * 4 || $this->commands > $this->options->limits->maxCommands * 8) {
            throw new LimitExceeded('Layout construction work budget exhausted.');
        }
    }
}
