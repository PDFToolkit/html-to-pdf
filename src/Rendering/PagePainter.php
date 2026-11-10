<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Document\ResourceDictionary;
use PDF\Document\Structure\MarkedContentContainer;
use PDF\Document\Structure\MarkedContentIdentifier;
use PDF\Document\Structure\MarkedContentReference;
use PDF\Document\Structure\ObjectReference;
use PDF\Document\Structure\StructureElement;
use PDF\Fonts\OpenType\PositionedText;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Layout\LayoutPage;
use PDF\HtmlToPdf\Style\Color;
use PDF\Specification\Dictionary\LinkAnnotationTable173;
use PDF\Specification\Dictionary\URIActionTable206;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\StrokePath;
use PDF\Syntax\DataStructure\Rectangle as PdfRectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

/** Converts one page's top-left layout coordinates into PDF operators and explicit associations. */
final class PagePainter
{
    public function __construct(
        private readonly FontResources $fonts,
        private readonly ImageResources $images,
        private readonly SemanticTree $semantics,
        private readonly ResourceDictionary $resources,
        private int $nextAnnotationKey,
    ) {}

    public function paint(LayoutPage $layout, int $pageKey): PageObject
    {
        $operators = [];
        $bindings = [];
        $annotations = [];
        $objects = [];
        /** @var array{uri:string,semanticId:string,baseline:float,end:float,bounds:Bounds}|null $link */
        $link = null;
        $close = function () use (&$link, &$annotations, &$objects): void {
            if ($link !== null) {
                $key = new IntegerType($this->nextAnnotationKey++);
                $annotation = $this->link($link['uri'], $link['bounds'], $key);
                $annotations[] = new IndirectReference($annotation);
                $objects[] = [$this->semantics->link($link['semanticId']), $annotation, $key];
                $link = null;
            }
        };
        foreach ($layout->commands as $command) {
            if (!$command instanceof Text || $command->uri === null) {
                $close();
            }
            if (!$command instanceof Text && !$command instanceof Rectangle && !$command instanceof Image && !$command instanceof BorderBox && !$command instanceof RadioButton) {
                throw new ConversionException('Unsupported drawing command.');
            }
            $semanticId = ($command instanceof Rectangle || $command instanceof BorderBox) ? null : $command->semanticId;
            if ($semanticId === null) {
                $operators[] = new BeginMarkedContent(new NameType('Artifact'));
            } else {
                $element = $this->semantics->element($semanticId);
                $identifier = new MarkedContentIdentifier(new IntegerType(count($bindings)));
                $bindings[] = [$element, $identifier];
                $operators[] = $identifier->begin($element->type);
            }
            $operators[] = new SaveGraphicsState();
            if ($command instanceof Text) {
                $font = $this->fonts->font($command);
                $run = $this->fonts->run($command);
                $operators[] = $this->color($command->color);
                array_push($operators, ...(new PositionedText($font->embedding(), $run->text))->operators(
                    $font->name,
                    new NumericType($command->size),
                    new NumericType($command->x),
                    new NumericType($layout->height - $command->y),
                ));
                if ($command->uri !== null) {
                    if ($command->semanticId === null) {
                        throw new ConversionException('Decorative text cannot create an unassociated interactive link.');
                    }
                    // Runs of one inline box differ only by face or size: they share one link rectangle.
                    $bounds = (new TextBounds())->calculate($command, $run, $layout->height);
                    if ($link !== null && $link['uri'] === $command->uri && $link['semanticId'] === $command->semanticId
                        && abs($link['baseline'] - $command->y) < 0.0001 && abs($link['end'] - $command->x) < 0.01) {
                        $link['bounds'] = $link['bounds']->union($bounds);
                    } else {
                        $close();
                        $link = ['uri' => $command->uri, 'semanticId' => $command->semanticId, 'baseline' => $command->y, 'bounds' => $bounds, 'end' => $command->x];
                    }
                    $link['end'] = $command->x + $run->width;
                }
            } elseif ($command instanceof Image) {
                $operators[] = new ConcatenateMatrix(new Matrix(
                    new NumericType($command->width),
                    new NumericType(0),
                    new NumericType(0),
                    new NumericType($command->height),
                    new NumericType($command->x),
                    new NumericType($layout->height - $command->y - $command->height),
                ));
                $operators[] = new InvokeXObject($this->images->name($command->asset));
            } elseif ($command instanceof RadioButton) {
                array_push($operators, ...(new RadioButtonPainter())->operators($command, $layout->height));
            } elseif ($command instanceof BorderBox) {
                array_push($operators, ...(new BorderPainter())->operators($command, $layout->height));
            } elseif ($command instanceof Rectangle) {
                array_push($operators, ...$this->rectangle($command, $layout->height));
            }
            $operators[] = new RestoreGraphicsState();
            $operators[] = new EndMarkedContent();
        }
        $close();
        $page = new PageObject(
            new PdfRectangle(new NumericType(0), new NumericType(0), new NumericType($layout->width), new NumericType($layout->height)),
            $this->resources,
            new StreamObject(new ContentStreams(...$operators)),
            new Entry(new NameType('StructParents'), new IntegerType($pageKey)),
            new Entry(new NameType('Annots'), new ArrayType(...$annotations)),
        );
        $container = new MarkedContentContainer($page, new IntegerType($pageKey));
        foreach ($bindings as [$element, $identifier]) {
            $element->addKid(new MarkedContentReference($container, $identifier));
        }
        foreach ($objects as [$element, $annotation, $key]) {
            $element->addKid(new ObjectReference($page, $annotation, $key));
        }

        return $page;
    }

    private function color(Color $color): SetNonstrokingRGBColor
    {
        return new SetNonstrokingRGBColor(new NumericType($color->red), new NumericType($color->green), new NumericType($color->blue));
    }

    /** @return list<Operator> */
    private function rectangle(Rectangle $rectangle, float $pageHeight): array
    {
        $path = new AppendRectangle(
            new NumericType($rectangle->x),
            new NumericType($pageHeight - $rectangle->y - $rectangle->height),
            new NumericType($rectangle->width),
            new NumericType($rectangle->height),
        );
        $operators = [];
        if ($rectangle->color !== null) {
            $operators = [$this->color($rectangle->color), $path, new FillPathNonzero()];
        }
        if ($rectangle->borderColor !== null && $rectangle->borderWidth > 0) {
            $color = $rectangle->borderColor;
            array_push(
                $operators,
                new SetStrokingRGBColor(new NumericType($color->red), new NumericType($color->green), new NumericType($color->blue)),
                new SetLineWidth(new NumericType($rectangle->borderWidth)),
                $path,
                new StrokePath(),
            );
        }

        return $operators;
    }

    private function link(string $uri, Bounds $bounds, IntegerType $key): LinkAnnotationTable173
    {
        if (preg_match('/[^\x21-\x7E]/', $uri) === 1 || !in_array(strtolower((string) parse_url($uri, PHP_URL_SCHEME)), ['http', 'https', 'mailto'], true)) {
            throw new UnsupportedFeature('Only explicit ASCII http, https and mailto link targets are supported.');
        }

        return new LinkAnnotationTable173(
            new Entry(new NameType('Type'), new NameType('Annot')),
            new Entry(new NameType('Subtype'), new NameType('Link')),
            new Entry(new NameType('Rect'), $bounds->pdf()),
            new Entry(new NameType('Border'), new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0))),
            new Entry(new NameType('StructParent'), $key),
            new Entry(new NameType('A'), new URIActionTable206(new Entry(new NameType('S'), new NameType('URI')), new Entry(new NameType('URI'), new LiteralString($uri)))),
        );
    }
}
