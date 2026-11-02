<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Rendering\BorderPainter;
use PDF\HtmlToPdf\Rendering\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BordersTest extends TestCase
{
    private function layout(string $html, float $pageHeight = 200): PagedDocument
    {
        $options = new ConversionOptions(new FontSet(new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'))), new PageSettings(200, $pageHeight, new Edges(10, 10, 10, 10)));
        $diagnostics = new Diagnostics();
        $source = new HtmlSource('<style>body {margin:0} </style>' . $html);
        $document = (new HtmlCompiler())->parse($source, $options, $diagnostics);
        return (new LayoutEngine())->layout($document, $options, $diagnostics);
    }

    public function testAsymmetricEdgesParticipateInContentBoxGeometry(): void
    {
        $result = $this->layout('<div style="width:100pt;height:40pt;padding:1pt 2pt 3pt 4pt;border-width:3pt 5pt 7pt 11pt;border-style:solid;border-color:red green blue black"></div>');
        $border = $result->pages[0]->commands[0];
        self::assertInstanceOf(BorderBox::class, $border);
        self::assertSame([10.0, 10.0, 122.0, 54.0], [$border->x, $border->y, $border->width, $border->height]);
        self::assertEquals(new Edges(3, 5, 7, 11), $border->borders->widths());
    }

    public function testTransparentBordersReserveTheSameSpaceAsVisibleBorders(): void
    {
        $source = '<div style="padding:2pt;border-width:3pt 5pt 7pt 11pt;border-style:solid;border-color:%s">Visible</div>';
        $visible = $this->layout(sprintf($source, 'red'));
        $transparent = $this->layout(sprintf($source, 'transparent'));
        $left = array_values(array_filter($visible->pages[0]->commands, static fn($command): bool => $command instanceof Text))[0];
        $right = array_values(array_filter($transparent->pages[0]->commands, static fn($command): bool => $command instanceof Text))[0];
        self::assertSame([23.0, $left->y], [$right->x, $right->y]);
    }

    public function testBordersAndPaddingStayWithFirstAndLastContentAtPageBreaks(): void
    {
        $result = $this->layout('<div style="white-space:pre;font-size:8pt;line-height:12pt;border-style:solid;border-width:5pt 2pt 7pt;orphans:1;widows:1">a<br>b<br>c<br>d<br>e</div>', 80);
        self::assertCount(2, $result->pages);
        $offsets = [];
        foreach ($result->pages as $index => $page) {
            $texts = array_values(array_filter($page->commands, static fn($command): bool => $command instanceof Text));
            self::assertNotEmpty($texts, 'No page may contain only detached borders.');
            $borders = array_values(array_filter($page->commands, static fn($command): bool => $command instanceof BorderBox));
            foreach ($borders as $border) {
                self::assertSame(72.0, $border->totalHeight);
                self::assertLessThanOrEqual(70, $border->y + $border->height);
                $offsets[] = $border->offset;
            }
            self::assertSame($index === 0 ? ['a', 'b', 'c', 'd'] : ['e'], array_column($texts, 'text'));
        }
        self::assertSame([0.0, 17.0, 29.0, 41.0, 53.0], $offsets);
    }

    public function testLeadingChildMarginCannotStrandTheTopBorderOnThePreviousPage(): void
    {
        $result = $this->layout('<div style="height:45pt"></div><div style="border-top:5pt solid"><p style="margin:20pt 0 0;font-size:8pt;line-height:12pt">Kept</p></div>', 100);
        self::assertCount(2, $result->pages);
        self::assertCount(0, array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof BorderBox));
        self::assertSame('Kept', array_values(array_filter($result->pages[1]->commands, static fn($command): bool => $command instanceof Text))[0]->text);
    }

    public function testFixedTableCellsUseEachBorderSideIndependently(): void
    {
        $result = $this->layout('<table style="table-layout:fixed;width:160pt;font-size:8pt;line-height:12pt"><tr><td style="padding:0;border-style:solid;border-width:2pt 4pt 6pt 8pt">A</td><td style="padding:0;border-left:3pt solid red">B</td></tr></table>');
        $texts = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Text));
        self::assertSame([18.0, 93.0], array_column($texts, 'x'));
        $borders = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof BorderBox));
        self::assertCount(2, $borders);
        self::assertSame([80.0, 80.0], array_column($borders, 'width'));
        self::assertSame([20.0, 20.0], array_column($borders, 'height'));
    }

    #[DataProvider('longPatternedBoxes')]
    public function testLongPatternedBordersKeepTheirRealLinearWorkBudget(int $lines, string $style, int $pages): void
    {
        $options = new ConversionOptions(new FontSet(new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'))));
        $diagnostics = new Diagnostics();
        $source = new HtmlSource('<style>*{margin:0;padding:0}div{font-size:8pt;line-height:12pt;border:1pt ' . $style . ' red;orphans:1;widows:1}</style><div>' . implode('<br>', array_fill(0, $lines, 'a')) . '</div>');
        $document = (new HtmlCompiler())->parse($source, $options, $diagnostics);
        $layout = (new LayoutEngine())->layout($document, $options, $diagnostics);
        self::assertCount($pages, $layout->pages);
        $work = 0.0;
        $commands = 0;
        foreach ($layout->pages as $page) {
            $commands += count($page->commands);
            foreach ($page->commands as $command) {
                if ($command instanceof BorderBox) {
                    $work += (new BorderPainter())->patternWork($command);
                }
            }
        }
        self::assertSame($lines * 2, $commands);
        // Two visible vertical paths per line, plus the two fixed page-width edges.
        self::assertLessThan($lines * 16 + 600, $work);
        self::assertStringStartsWith('%PDF-', (new Renderer())->render($layout));
    }

    public static function longPatternedBoxes(): iterable
    {
        yield '100 dashed lines' => [100, 'dashed', 2];
        yield '150 dashed lines' => [150, 'dashed', 3];
        yield '100 dotted lines' => [100, 'dotted', 2];
        yield '150 dotted lines' => [150, 'dotted', 3];
    }

    public function testInlineBorderContextsAreExplicitlyRejected(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->layout('<p>Before <span style="border-left:2pt dotted red">Inline</span> after</p>');
    }

    public function testOnlyTheMatchingVerticalBorderPreventsMarginCollapse(): void
    {
        $left = $this->layout('<div style="border-left:5pt solid"><div style="margin-top:7pt;height:10pt"></div></div>');
        $top = $this->layout('<div style="border-top:5pt solid"><div style="margin-top:7pt;height:10pt"></div></div>');
        self::assertSame(17.0, $left->pages[0]->commands[0]->y);
        self::assertSame(10.0, $top->pages[0]->commands[0]->y);
    }
}
