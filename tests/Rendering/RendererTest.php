<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Rendering;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Layout\LayoutPage;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Model\SemanticNode;
use PDF\HtmlToPdf\Rendering\Renderer;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Style\Color;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\PageTreeReader;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function fixture(string $name): string
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name);
        self::assertIsString($bytes);

        return $bytes;
    }

    private function document(?string $uri = 'https://pdftoolkit.github.io/'): PagedDocument
    {
        $font = new FontFace($this->fixture('fonts/NotoSans-Regular.ttf'));
        $page = new LayoutPage(595, 842, [
            new Rectangle(30, 30, 535, 200, new Color(0.95, 0.95, 0.95)),
            new Text($font, 'Bonjour, été : AV fi.', 14, 50, 100, new Color(), 'text'),
            new Text($font, 'Documentation', 14, 50, 140, new Color(0, 0, 1), 'link-text', $uri),
            new Image(50, 180, 96, 72, new ImageAsset($this->fixture('images/rgba.png')), 'figure'),
            new Image(170, 180, 96, 72, new ImageAsset($this->fixture('images/rgb.jpg'))),
        ]);

        return new PagedDocument([$page], [new SemanticNode('document', 'Document'), new SemanticNode('p', 'P', 'document'),
            new SemanticNode('text', 'Span', 'p'), new SemanticNode('link', 'Link', 'p'), new SemanticNode('link-text', 'Span', 'link'),
            new SemanticNode('figure', 'Figure', 'document', 'An original color pattern')], 'Épreuve Unicode', 'fr-FR');
    }

    public function testBuildsTaggedUnicodeImagesAndLinkedAnnotation(): void
    {
        $pdf = (new Renderer())->render($this->document());
        foreach (['/ToUnicode', '/FontFile2', '/SMask', '/DCTDecode', '/StructTreeRoot', '/ParentTree', '/ActualText', '/URI', '/OBJR', '/BBox'] as $token) {
            self::assertStringContainsString($token, $pdf);
        }
        $read = (new DocumentReader())->read(new FileBytes($pdf));
        $data = (new DocumentInspector())->inspect($read)->jsonSerialize();
        self::assertFalse($data['truncated']);
        self::assertSame([], $data['diagnostics']);
        self::assertCount(1, $data['pages']);
        self::assertCount(1, array_filter($data['nodes'], static fn(array $node): bool => $node['kind'] === 'objr'));
        self::assertCount(3, array_filter($data['nodes'], static fn(array $node): bool => $node['kind'] === 'mcr'));
        $page = (new PageTreeReader($read))->read()->page(0);
        $contents = $read->resolve($page->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $contents);
        $operators = $read->streamData($contents);
        self::assertStringContainsString('50 742 Tm', $operators);
        self::assertStringContainsString('96 0 0 72 50 590 cm', $operators);
        self::assertStringContainsString('/Artifact BMC', $operators);
    }

    public function testAParagraphMaySpanPagesWithoutLosingItsIdentity(): void
    {
        $font = new FontFace($this->fixture('fonts/NotoSans-Regular.ttf'));
        $pages = [];
        foreach (['First fragment', 'Second fragment'] as $text) {
            $pages[] = new LayoutPage(300, 400, [new Text($font, $text, 12, 20, 40, new Color(), 'p')]);
        }
        $document = new PagedDocument($pages, [new SemanticNode('root', 'Document'), new SemanticNode('p', 'P', 'root')]);
        $read = (new DocumentReader())->read(new FileBytes((new Renderer())->render($document)));
        $data = (new DocumentInspector())->inspect($read)->jsonSerialize();
        self::assertCount(2, $data['pages']);
        $mcr = array_values(array_filter($data['nodes'], static fn(array $node): bool => $node['kind'] === 'mcr'));
        self::assertCount(2, $mcr);
        self::assertSame($mcr[0]['parentId'], $mcr[1]['parentId']);
        self::assertSame([1, 2], array_column(array_column($mcr, 'page'), 'number'));
    }

    public function testRejectsActiveUriActions(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new Renderer())->render($this->document('javascript:alert(1)'));
    }

    public function testRejectsMissingSemanticParents(): void
    {
        $document = new PagedDocument([new LayoutPage(300, 400, [])], [new SemanticNode('root', 'Document'), new SemanticNode('p', 'P', 'missing')]);
        $this->expectException(ConversionException::class);
        (new Renderer())->render($document);
    }

    public function testRepeatedTextIsAnArtifactWithoutDuplicatingStructure(): void
    {
        $font = new FontFace($this->fixture('fonts/NotoSans-Regular.ttf'));
        $document = new PagedDocument([
            new LayoutPage(300, 400, [new Text($font, 'Header', 12, 20, 40, new Color(), 'header')]),
            new LayoutPage(300, 400, [new Text($font, 'Header', 12, 20, 40, new Color(), null)]),
        ], [new SemanticNode('root', 'Document'), new SemanticNode('header', 'P', 'root')]);
        $read = (new DocumentReader())->read(new FileBytes((new Renderer())->render($document)));
        $data = (new DocumentInspector())->inspect($read)->jsonSerialize();
        self::assertCount(1, array_filter($data['nodes'], static fn(array $node): bool => $node['kind'] === 'mcr'));
        $page = (new PageTreeReader($read))->read()->page(1);
        $contents = $read->resolve($page->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $contents);
        self::assertStringContainsString('/Artifact BMC', $read->streamData($contents));
    }

    public function testRejectsAmbiguousMixedStructuralAndPaintOrder(): void
    {
        $font = new FontFace($this->fixture('fonts/NotoSans-Regular.ttf'));
        $document = new PagedDocument(
            [new LayoutPage(300, 400, [new Text($font, 'Text', 12, 20, 40, new Color(), 'p')])],
            [new SemanticNode('root', 'Document'), new SemanticNode('p', 'P', 'root'), new SemanticNode('child', 'Span', 'p')],
        );
        $this->expectException(ConversionException::class);
        (new Renderer())->render($document);
    }

    public function testRejectsSemanticCycles(): void
    {
        $document = new PagedDocument([new LayoutPage(300, 400, [])], [new SemanticNode('root', 'Document'), new SemanticNode('p', 'P', 'q'), new SemanticNode('q', 'P', 'p')]);
        $this->expectException(LimitExceeded::class);
        (new Renderer(new ConversionLimits(maxDepth: 4)))->render($document);
    }

    public function testCountsCumulativeResourcesAndOutput(): void
    {
        $this->expectException(LimitExceeded::class);
        (new Renderer(new ConversionLimits(maxResourceBytes: 100)))->render($this->document());
    }

    public function testBoundsOutputBytes(): void
    {
        $this->expectException(LimitExceeded::class);
        (new Renderer(new ConversionLimits(maxOutputBytes: 100)))->render(new PagedDocument([new LayoutPage(300, 400, [])], [new SemanticNode('root', 'Document')]));
    }

    public function testDoesNotRetainPriorDocuments(): void
    {
        $renderer = new Renderer();
        $renderer->render($this->document());
        $empty = new PagedDocument([new LayoutPage(300, 400, [])], [new SemanticNode('root', 'Document')]);
        $second = $renderer->render($empty);
        self::assertSame((new Renderer())->render($empty), $second);
        self::assertStringNotContainsString('/SMask', $second);
        self::assertStringNotContainsString('/FontFile2', $second);
    }
}
