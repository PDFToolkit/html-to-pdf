<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Document\DocumentInformation;
use PDF\Document\Information\Title;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Resources\XObject;
use PDF\Document\Structure\MarkInformation;
use PDF\Document\Structure\StructureLimits;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\FileStructure;
use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Layout\LayoutPage;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Lang;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Stateless facade: each render owns its fonts, resources, parent keys and structure graph. */
final readonly class Renderer
{
    public function __construct(private ConversionLimits $limits = new ConversionLimits()) {}

    public function render(PagedDocument $document): string
    {
        $budget = new RenderBudget($this->limits);
        $fonts = new FontResources($budget);
        $images = new ImageResources($budget);
        $this->prepare($document, $fonts, $images);
        $semantics = new SemanticTree($document, $fonts, $this->limits);
        $resources = new ResourceDictionary(new Font($fonts->dictionary()), new XObject($images->dictionary()));
        $pages = new PageTreeNode();
        $painter = new PagePainter($fonts, $images, $semantics, $resources, count($document->pages));
        foreach ($document->pages as $key => $page) {
            $pages->addKid($painter->paint($page, $key));
        }
        $root = new StructureTreeRoot($pages, limits: new StructureLimits(
            maximumElements: $this->limits->maxNodes,
            maximumParentArrayLength: $this->limits->maxCommands,
        ));
        $semantics->attach($root);
        $catalog = new DocumentCatalog(
            $pages,
            new Lang(new TextString($document->language)),
            new Entry(new NameType('ViewerPreferences'), new DictionaryType(new Entry(new NameType('DisplayDocTitle'), new BooleanType(true)))),
            ...$root->catalogEntries(new MarkInformation(new BooleanType(true))),
        );
        $information = new DocumentInformation(new Title(new TextString($document->title)));
        $file = new FileStructure(
            new FileHeader('1.7'),
            new FileBody($catalog),
            new IndirectReference($catalog),
            new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information))),
        );
        $bytes = $file->toPdf();
        if (strlen($bytes) > $this->limits->maxOutputBytes) {
            throw new LimitExceeded('Serialized PDF exceeds the output byte budget.');
        }

        return $bytes;
    }

    private function prepare(PagedDocument $document, FontResources $fonts, ImageResources $images): void
    {
        $pages = $this->orderedPages($document->pages);
        $count = 0;
        $borderWork = 0.0;
        foreach ($pages as $page) {
            if (!is_finite($page->width) || !is_finite($page->height)
                || min($page->width, $page->height) <= 0 || max($page->width, $page->height) > 14_400) {
                throw new ConversionException('Page dimensions and command order are invalid.');
            }
            $commands = $this->ordered($page->commands);
            foreach ($commands as $command) {
                if (++$count > $this->limits->maxCommands) {
                    throw new LimitExceeded('Cumulative drawing command budget exceeded.');
                }
                if (!$command instanceof Text && !$command instanceof Rectangle && !$command instanceof Image && !$command instanceof BorderBox) {
                    throw new ConversionException('Unknown drawing command.');
                }
                if (!is_finite($command->x) || !is_finite($command->y)) {
                    throw new ConversionException('Drawing positions must be finite.');
                }
                if ($command instanceof BorderBox) {
                    $borderWork += (new BorderPainter())->patternWork($command);
                    if (!is_finite($borderWork) || $borderWork > $this->limits->maxCommands) {
                        throw new LimitExceeded('Cumulative border pattern work exceeds the drawing budget.');
                    }
                }
                if ($command instanceof Text) {
                    $fonts->prepare($command);
                } else {
                    if (!is_finite($command->width) || !is_finite($command->height) || min($command->width, $command->height) < 0
                        || ($command instanceof Image && min($command->width, $command->height) == 0)) {
                        throw new ConversionException('Drawing dimensions must be finite and non-negative.');
                    }
                    if ($command instanceof Image) {
                        $images->prepare($command->asset);
                    } elseif ($command instanceof Rectangle && (!is_finite($command->borderWidth) || $command->borderWidth < 0)) {
                        throw new ConversionException('Border width must be finite and non-negative.');
                    }
                }
            }
        }
    }

    /** @return list<LayoutPage> */
    private function orderedPages(mixed $pages): array
    {
        $pages = $this->ordered($pages);
        if ($pages === [] || count($pages) > $this->limits->maxPages) {
            throw new LimitExceeded('The document requires a bounded, non-empty ordered page list.');
        }
        $result = [];
        foreach ($pages as $page) {
            if (!$page instanceof LayoutPage) {
                throw new ConversionException('Every page must be an explicit LayoutPage.');
            }
            $result[] = $page;
        }

        return $result;
    }

    /** @return list<mixed> */
    private function ordered(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new ConversionException('Pages and drawing commands require ordered lists.');
        }

        return $values;
    }
}
