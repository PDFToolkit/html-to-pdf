<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Document\Structure\AttributeAssociation;
use PDF\Document\Structure\StandardStructureType;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Model\SemanticNode;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;

/** Preserves semantic order independently of page order and paint order. */
final class SemanticTree
{
    /** @var array<string, SemanticNode> */
    private array $sources = [];
    /** @var array<string, StructureElement> */
    private array $elements = [];
    /** @var array<string, array<int, Bounds>> */
    private array $bounds = [];
    private string $rootId;

    /** @return list<SemanticNode> */
    private function orderedSources(mixed $sources): array
    {
        if (!is_array($sources) || !array_is_list($sources)) {
            throw new ConversionException('Semantic nodes must be an ordered list.');
        }
        $ordered = [];
        foreach ($sources as $source) {
            if (!$source instanceof SemanticNode) {
                throw new ConversionException('Every semantic node must be an explicit SemanticNode.');
            }
            $ordered[] = $source;
        }

        return $ordered;
    }

    public function __construct(PagedDocument $document, FontResources $fonts, ConversionLimits $limits)
    {
        $sources = $this->orderedSources($document->semantics);
        if (count($sources) > $limits->maxNodes) {
            throw new LimitExceeded('Semantic nodes must be a bounded ordered list.');
        }
        $roots = [];
        foreach ($sources as $source) {
            if ($source->id === '' || strlen($source->id) > 1024 || isset($this->sources[$source->id])
                || StandardStructureType::tryFrom($source->role) === null) {
                throw new ConversionException('Semantic identities must be unique and use supported standard PDF roles.');
            }
            $this->sources[$source->id] = $source;
            if ($source->parentId === null) {
                $roots[] = $source;
            }
        }
        if (count($roots) !== 1 || $roots[0]->role !== 'Document') {
            throw new ConversionException('One Document semantic root is required.');
        }
        $this->rootId = $roots[0]->id;
        foreach ($this->sources as $source) {
            $depth = 0;
            $cursor = $source;
            while ($cursor->parentId !== null) {
                if (++$depth > $limits->maxDepth) {
                    throw new LimitExceeded('Semantic parents contain a cycle or exceed the depth budget.');
                }
                $cursor = $this->sources[$cursor->parentId] ?? throw new ConversionException('Semantic parent does not exist.');
            }
        }
        $containers = [];
        foreach ($this->sources as $source) {
            if ($source->parentId !== null) {
                $containers[$source->parentId] = true;
            }
        }
        foreach ($document->pages as $pageIndex => $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof Text) {
                    $bounds = (new TextBounds())->calculate($command, $fonts->run($command), $page->height);
                } elseif ($command instanceof Rectangle || $command instanceof Image) {
                    $bounds = new Bounds($command->x, $page->height - $command->y - $command->height, $command->x + $command->width, $page->height - $command->y);
                } else {
                    throw new ConversionException('Unsupported drawing command.');
                }
                if ($command->semanticId !== null) {
                    if (!$command instanceof Rectangle && isset($containers[$command->semanticId])) {
                        throw new ConversionException('Paint semantic content in leaf nodes so its order cannot conflict with structural children.');
                    }
                    $this->recordBounds($command->semanticId, $pageIndex, $bounds);
                }
            }
        }
        foreach ($this->sources as $source) {
            $entries = [];
            if ($source->alt !== null) {
                $entries[] = new Entry(new NameType('Alt'), new TextString($source->alt));
            }
            if ($source->language !== null) {
                $entries[] = new Entry(new NameType('Lang'), new TextString($source->language));
            }
            $this->elements[$source->id] = new StructureElement(
                StandardStructureType::from($source->role),
                null,
                new LiteralString($source->id),
                $this->attributes($source),
                null,
                new IntegerType(0),
                ...$entries,
            );
        }
        foreach ($this->sources as $source) {
            if ($source->parentId !== null) {
                $this->elements[$source->parentId]->addKid($this->elements[$source->id]);
            }
        }
    }

    public function attach(StructureTreeRoot $root): void
    {
        $root->addKid($this->elements[$this->rootId]);
    }

    public function element(string $id): StructureElement
    {
        return $this->elements[$id] ?? throw new ConversionException('Drawing references an unknown semantic node.');
    }

    public function link(string $id): StructureElement
    {
        $source = $this->sources[$id] ?? throw new ConversionException('Link references an unknown semantic node.');
        while ($source->role !== 'Link') {
            if ($source->parentId === null) {
                throw new ConversionException('A URI drawing requires a Link semantic ancestor.');
            }
            $source = $this->sources[$source->parentId];
        }

        return $this->elements[$source->id];
    }

    private function recordBounds(string $id, int $page, Bounds $bounds): void
    {
        $source = $this->sources[$id] ?? throw new ConversionException('Drawing references an unknown semantic node.');
        do {
            if (in_array($source->role, ['Table', 'Figure'], true)) {
                $previous = $this->bounds[$source->id][$page] ?? null;
                $this->bounds[$source->id][$page] = $previous === null ? $bounds : $previous->union($bounds);
            }
            $source = $source->parentId === null ? null : $this->sources[$source->parentId];
        } while ($source !== null);
    }

    private function attributes(SemanticNode $source): ?StructureAttributes
    {
        $attributes = [];
        $bounds = $this->bounds[$source->id] ?? [];
        if (in_array($source->role, ['Table', 'Figure'], true) && $bounds !== []) {
            $entries = [new Entry(new NameType('O'), new NameType('Layout'))];
            if (count($bounds) === 1) {
                $entries[] = new Entry(new NameType('BBox'), array_values($bounds)[0]->pdf());
            }
            if ($source->role === 'Figure') {
                $width = max(array_map(static fn(Bounds $box): float => $box->right - $box->left, $bounds));
                $height = max(array_map(static fn(Bounds $box): float => $box->top - $box->bottom, $bounds));
                $entries[] = new Entry(new NameType('Placement'), new NameType('Inline'));
                $entries[] = new Entry(new NameType('Width'), new NumericType($width));
                $entries[] = new Entry(new NameType('Height'), new NumericType($height));
            }
            $attributes[] = new AttributeAssociation(new StructureAttributeObject(new DictionaryType(...$entries)));
        }
        if ($source->scope !== null) {
            if ($source->role !== 'TH' || !in_array($source->scope, ['Row', 'Column', 'Both'], true)) {
                throw new ConversionException('Only a table header may declare a supported Scope.');
            }
            $attributes[] = new AttributeAssociation(new StructureAttributeObject(new DictionaryType(
                new Entry(new NameType('O'), new NameType('Table')),
                new Entry(new NameType('Scope'), new NameType($source->scope)),
            )));
        }

        return $attributes === [] ? null : new StructureAttributes(...$attributes);
    }
}
