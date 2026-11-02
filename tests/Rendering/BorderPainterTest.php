<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Rendering;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Layout\LayoutPage;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Model\SemanticNode;
use PDF\HtmlToPdf\Rendering\BorderPainter;
use PDF\HtmlToPdf\Rendering\Renderer;
use PDF\HtmlToPdf\Style\BorderSide;
use PDF\HtmlToPdf\Style\Borders;
use PDF\HtmlToPdf\Style\BorderStyle;
use PDF\HtmlToPdf\Style\Color;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BorderPainterTest extends TestCase
{
    #[DataProvider('styles')]
    public function testEveryCss1StyleSerializesAsArtifactWithClipping(BorderStyle $style): void
    {
        $border = new BorderBox(10, 10, 100, 40, Borders::uniform(new BorderSide(6, $style, new Color(0.4, 0.6, 0.8))), 0, 40, 'root');
        $pdf = (new Renderer())->render(new PagedDocument([new LayoutPage(200, 200, [$border])], [new SemanticNode('root', 'Document')]));
        $read = (new DocumentReader())->read(new FileBytes($pdf));
        $page = (new PageTreeReader($read))->read()->page(0);
        $contents = $read->resolve($page->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $contents);
        $operators = $read->streamData($contents);
        self::assertStringContainsString('/Artifact BMC', $operators);
        self::assertStringNotContainsString('/MCID', $operators);
        if ($style !== BorderStyle::None) {
            self::assertStringContainsString('10 150 100 40 re', $operators);
            self::assertStringContainsString('W', $operators);
        }
        if ($style === BorderStyle::Dotted) {
            self::assertStringContainsString('[0 12] 0 d', $operators);
            self::assertStringContainsString('1 J', $operators);
        }
        if ($style === BorderStyle::Dashed) {
            self::assertStringContainsString('[18 18] 0 d', $operators);
        }
    }

    public static function styles(): iterable
    {
        foreach (BorderStyle::cases() as $style) {
            yield $style->value => [$style];
        }
    }

    public function testUnequalSidesMeetAtTheContentCornersWithoutOverpainting(): void
    {
        $borders = new Borders(new BorderSide(2, BorderStyle::Solid, new Color(1, 0, 0)), new BorderSide(4, BorderStyle::Solid, new Color(0, 1, 0)), new BorderSide(6, BorderStyle::Solid, new Color(0, 0, 1)), new BorderSide(8, BorderStyle::Solid));
        $operators = (string) (new ContentStreams(...(new BorderPainter())->operators(new BorderBox(10, 20, 100, 40, $borders, 0, 40), 200)));
        self::assertStringContainsString("10 180 m\n110 180 l\n106 178 l\n18 178 l\nh", $operators);
        self::assertStringContainsString("110 180 m\n110 140 l\n106 146 l\n106 178 l\nh", $operators);
    }

    public function testDoubleHasTwoBandsPerSideAndTransparentHasNoPaint(): void
    {
        $painter = new BorderPainter();
        $double = $painter->operators(new BorderBox(0, 0, 100, 40, Borders::uniform(new BorderSide(6, BorderStyle::Double)), 0, 40), 200);
        self::assertCount(8, array_filter($double, static fn($op): bool => $op instanceof MoveTo));
        self::assertCount(1, array_filter($double, static fn($op): bool => $op instanceof FillPathNonzero));
        $transparent = $painter->operators(new BorderBox(0, 0, 100, 40, Borders::uniform(new BorderSide(6, BorderStyle::Solid, null)), 0, 40), 200);
        self::assertCount(0, array_filter($transparent, static fn($op): bool => $op instanceof FillPathNonzero));
    }

    public function testTinyPatternsAreRejectedBeforeCreatingAnExpensiveViewerWorkload(): void
    {
        $this->expectException(LimitExceeded::class);
        $border = new BorderBox(0, 0, 100, 40, Borders::uniform(new BorderSide(0.000001, BorderStyle::Dotted)), 0, 40);
        (new Renderer())->render(new PagedDocument([new LayoutPage(200, 200, [$border])], [new SemanticNode('root', 'Document')]));
    }

    public function testPatternBudgetIsCumulativeAcrossCommands(): void
    {
        $border = new BorderBox(0, 0, 10, 10, Borders::uniform(new BorderSide(1, BorderStyle::Dotted)), 0, 10);
        $document = new PagedDocument([new LayoutPage(200, 200, [$border])], [new SemanticNode('root', 'Document')]);
        self::assertStringStartsWith('%PDF-', (new Renderer(new ConversionLimits(maxCommands: 30)))->render($document));
        $this->expectException(LimitExceeded::class);
        (new Renderer(new ConversionLimits(maxCommands: 30)))->render(new PagedDocument([new LayoutPage(200, 200, [$border, $border])], $document->semantics));
    }

    public function testSliceUsesOriginalPatternCoordinatesAndOnlyItsOwnClip(): void
    {
        $borders = Borders::uniform(new BorderSide(3, BorderStyle::Dashed));
        $operators = (string) (new ContentStreams(...(new BorderPainter())->operators(new BorderBox(10, 20, 100, 20, $borders, 30, 80), 200)));
        self::assertStringContainsString('10 160 100 20 re', $operators);
        self::assertStringContainsString("11.5 210 m\n11.5 130 l", $operators);
    }

    public function testInvalidSliceGeometryFailsBeforeSerialization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BorderBox(0, 0, 100, 20, new Borders(), 30, 40);
    }
}
