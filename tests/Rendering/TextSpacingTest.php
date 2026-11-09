<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Rendering;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Layout\CommandTranslation;
use PDF\HtmlToPdf\Layout\LayoutPage;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Model\SemanticNode;
use PDF\HtmlToPdf\Rendering\FontResources;
use PDF\HtmlToPdf\Rendering\RenderBudget;
use PDF\HtmlToPdf\Rendering\Renderer;
use PDF\HtmlToPdf\Rendering\TextBounds;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;

final class TextSpacingTest extends TestCase
{
    private static FontFace $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(dirname(__DIR__) . '/Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    private function command(): Text
    {
        return new Text(self::$font, 'HH', 12, 10, 30, new Color(), 'text', 'https://example.test/', new TextSpacing(letterSpacing: 4));
    }

    public function testTranslationPreservesTheExactSpacingAndMeasuredFontRun(): void
    {
        $source = $this->command();
        $translated = (new CommandTranslation())->move($source, 20, 40);
        self::assertInstanceOf(Text::class, $translated);
        self::assertSame($source->spacing, $translated->spacing);
        self::assertSame(30.0, $translated->x);
        self::assertSame(70.0, $translated->y);
        $fonts = new FontResources(new RenderBudget(new ConversionLimits()));
        $fonts->prepare($translated);
        self::assertEqualsWithDelta(self::$font->measure('HH', 12) + 8, $fonts->run($translated)->width, 0.000001);
        self::assertSame($source->spacing, $fonts->run($translated)->spacing);
    }

    public function testPdfGlyphPositionsAndLinkBoundsUseTheSpacedRun(): void
    {
        $command = $this->command();
        $document = new PagedDocument([new LayoutPage(300, 400, [$command])], [new SemanticNode('root', 'Document'), new SemanticNode('link', 'Link', 'root'), new SemanticNode('text', 'Span', 'link')]);
        $pdf = (new Renderer())->render($document);
        $read = (new DocumentReader())->read(new FileBytes($pdf));
        $page = (new PageTreeReader($read))->read()->page(0);
        $stream = $read->resolve($page->dictionary->get('Contents'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        $operators = $read->streamData($stream);
        self::assertSame(2, preg_match_all('/1 0 0 1 ([0-9.]+) 370 Tm/', $operators, $matches));
        self::assertEqualsWithDelta(10, (float) $matches[1][0], 0.000001);
        self::assertEqualsWithDelta(10 + self::$font->measure('H', 12) + 4, (float) $matches[1][1], 0.000001);
        self::assertStringContainsString('/ActualText <FEFF00480048>', $operators);

        $annotations = $read->resolve($page->dictionary->get('Annots'));
        self::assertInstanceOf(ArrayType::class, $annotations);
        self::assertCount(1, $annotations);
        $link = $read->resolve($annotations->items[0]);
        self::assertInstanceOf(DictionaryType::class, $link);
        $rectangle = $read->resolve($link->get('Rect'));
        self::assertInstanceOf(ArrayType::class, $rectangle);
        $expected = (new TextBounds())->calculate($command, self::$font->shape('HH', 12, $command->spacing), 400);
        foreach ([$expected->left, $expected->bottom, $expected->right, $expected->top] as $index => $coordinate) {
            self::assertInstanceOf(NumericType::class, $rectangle->items[$index]);
            self::assertEqualsWithDelta($coordinate, $rectangle->items[$index]->value, 0.000001);
        }
    }

    public function testSpacedGlyphsStillConsumeTheCumulativeRenderingBudget(): void
    {
        $fonts = new FontResources(new RenderBudget(new ConversionLimits(maxTextCharacters: 3)));
        $command = $this->command();
        $fonts->prepare($command);
        $this->expectException(LimitExceeded::class);
        $fonts->prepare($command);
    }
}
