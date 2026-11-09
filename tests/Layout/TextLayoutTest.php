<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Rectangle;
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
use PDF\HtmlToPdf\Style\TextSpacing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextLayoutTest extends TestCase
{
    private static FontFace $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    public function testIndentReducesOnlyFirstWrappedLine(): void
    {
        $texts = $this->texts($this->layout('<p style="width:100pt;text-indent:30pt">' . str_repeat('alpha beta ', 8) . '</p>'));
        self::assertGreaterThan(2, count($texts));
        self::assertSame(50.0, $texts[0]->x);
        foreach (array_slice($texts, 1) as $text) {
            self::assertSame(20.0, $text->x);
        }
        self::assertLessThan(strlen($texts[1]->text), strlen($texts[0]->text));
    }

    #[DataProvider('explicitBreaks')]
    public function testExplicitBreakDoesNotRepeatIndent(string $html): void
    {
        $texts = $this->texts($this->layout($html));
        self::assertSame(['alpha', 'beta'], array_column($texts, 'text'));
        self::assertSame([32.0, 20.0], array_column($texts, 'x'));
    }

    public static function explicitBreaks(): iterable
    {
        yield ['<p style="text-indent:12pt">alpha<br>beta</p>'];
        yield ["<p style=\"text-indent:12pt;white-space:pre\">alpha\nbeta</p>"];
        yield ["<p style=\"text-indent:12pt;white-space:pre-wrap\">alpha\nbeta</p>"];
    }

    #[DataProvider('blocks')]
    public function testAnonymousContentAfterBlockDoesNotRestartIndent(string $middle): void
    {
        $texts = $this->texts($this->layout('<div style="text-indent:12pt">alpha' . $middle . 'gamma</div>'));
        self::assertSame(32.0, $texts[0]->x);
        self::assertSame(20.0, $texts[array_key_last($texts)]->x);
        if (count($texts) === 3) {
            self::assertSame(32.0, $texts[1]->x);
        }
    }

    public static function blocks(): iterable
    {
        yield ['<div>beta</div>'];
        yield ['<div></div>'];
        yield ['<span><div>beta</div></span>'];
    }

    public function testLeadingEmptyBlockConsumesAnonymousFirstLineEligibility(): void
    {
        $texts = $this->texts($this->layout('<div style="text-indent:12pt"><div></div>gamma</div>'));
        self::assertSame(20.0, $texts[0]->x);
    }

    public function testCollapsedWhitespaceAndHiddenContentDoNotConsumeFirstLine(): void
    {
        $texts = $this->texts($this->layout('<p style="text-indent:12pt">   <span style="display:none">hidden</span>alpha</p>'));
        self::assertSame(32.0, $texts[0]->x);
    }

    public function testLeadingExplicitEmptyLineConsumesIndent(): void
    {
        $texts = $this->texts($this->layout('<p style="text-indent:12pt"><br>alpha</p>'));
        self::assertSame(20.0, $texts[0]->x);
    }

    public function testInheritedPercentageResolvesAgainstEachContentWidth(): void
    {
        $texts = $this->texts($this->layout('<section style="width:180pt;text-indent:10%"><p style="width:100pt">alpha</p><p style="width:150pt">beta</p></section>'));
        self::assertSame([30.0, 35.0], array_column($texts, 'x'));
    }

    public function testInlineIndentValueDoesNotReplaceContainerIndent(): void
    {
        $texts = $this->texts($this->layout('<p style="text-indent:12pt"><span style="text-indent:50pt">alpha</span></p>'));
        self::assertSame(32.0, $texts[0]->x);
    }

    public function testNegativeIndentExpandsFirstLineAndResetsOnBreak(): void
    {
        $word = str_repeat('a', 15);
        self::assertGreaterThan(80, self::$font->measure($word, 10));
        $texts = $this->texts($this->layout('<p style="width:80pt;text-indent:-20pt">' . $word . '<br>beta</p>'));
        self::assertSame([0.0, 20.0], array_column($texts, 'x'));
    }

    #[DataProvider('alignments')]
    public function testIndentParticipatesInAlignment(string $alignment, float $indent): void
    {
        $text = $this->texts($this->layout('<p style="width:100pt;text-align:' . $alignment . ';text-indent:' . $indent . 'pt">alpha</p>'))[0];
        $unused = 100 - $indent - self::$font->measure('alpha', 10);
        $expected = 20 + $indent + match ($alignment) {
            'center' => $unused / 2,
            'right' => $unused,
            default => 0,
        };
        self::assertEqualsWithDelta($expected, $text->x, 0.00001);
    }

    public static function alignments(): iterable
    {
        foreach (['left', 'center', 'right'] as $alignment) {
            foreach ([12.0, -12.0] as $indent) {
                yield [$alignment, $indent];
            }
        }
    }

    public function testPageContinuationDoesNotRestartIndent(): void
    {
        $layout = $this->layout('<p style="width:80pt;line-height:18pt;orphans:1;widows:1;text-indent:12pt">' . str_repeat('alpha beta ', 12) . '</p>', new PageSettings(200, 80, new Edges(10, 10, 10, 10)));
        self::assertGreaterThan(1, count($layout->pages));
        self::assertSame(22.0, $this->texts($layout, 0)[0]->x);
        self::assertSame(10.0, $this->texts($layout, 1)[0]->x);
    }

    public function testFloatExclusionDoesNotChangePercentageBasis(): void
    {
        $texts = $this->texts($this->layout('<div style="float:left;width:40pt;height:12pt"></div><p style="width:180pt;text-indent:10%">alpha<br>beta</p>'));
        self::assertSame([78.0, 20.0], array_column($texts, 'x'));
    }

    public function testPositiveIndentIsIncludedWhenMovingFirstWordBelowFloat(): void
    {
        $texts = $this->texts($this->layout('<div style="float:left;width:150pt;height:24pt"></div><p style="width:180pt;text-indent:10pt">AAAAAAAAAA</p>'));
        self::assertSame(30.0, $texts[0]->x);
        self::assertGreaterThan(44.0, $texts[0]->y);
    }

    public function testInheritedSpacingChangesWrappingAndPreservesNbsp(): void
    {
        $plain = self::$font->measure('AA BB', 10);
        $layout = $this->layout('<p style="width:' . ($plain + 1) . 'pt">AA BB</p><p style="width:' . ($plain + 1) . 'pt;word-spacing:3pt">AA BB</p>');
        self::assertSame(['AA BB', 'AA', 'BB'], array_column($this->texts($layout), 'text'));
        $this->expectException(UnsupportedFeature::class);
        $this->layout('<p style="width:' . ($plain + 1) . 'pt;word-spacing:3pt">AA&nbsp;BB</p>');
    }

    public function testSpacingCacheAndInlineTransitionsRetainExactWidths(): void
    {
        $texts = $this->texts($this->layout('<p><span style="letter-spacing:2pt">AB</span><span style="letter-spacing:6pt">AB</span><span>AB</span></p>'));
        self::assertCount(3, $texts);
        self::assertEqualsWithDelta(20 + self::$font->measure('AB', 10, new TextSpacing(2)), $texts[1]->x, 0.00001);
        self::assertEqualsWithDelta($texts[1]->x + self::$font->measure('AB', 10, new TextSpacing(6)), $texts[2]->x, 0.00001);
        self::assertSame(2.0, $texts[0]->spacing->letterSpacing);
        self::assertSame(6.0, $texts[1]->spacing->letterSpacing);
    }

    public function testBackgroundAndUnderlineShareTheSpacedAdvance(): void
    {
        $layout = $this->layout('<p><a href="https://example.test/" style="letter-spacing:2pt;word-spacing:3pt;text-decoration:underline;background-color:yellow">AA BB</a></p>');
        $text = $this->texts($layout)[0];
        $width = self::$font->measure('AA BB', 10, $text->spacing);
        $rectangles = array_values(array_filter($layout->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle));
        self::assertCount(2, $rectangles);
        foreach ($rectangles as $rectangle) {
            self::assertEqualsWithDelta($width, $rectangle->width, 0.00001);
            self::assertSame($text->x, $rectangle->x);
        }
    }

    public function testSplitCombiningClusterWithLetterSpacingIsExplicitlyRejected(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('grapheme split');
        $this->layout('<p style="letter-spacing:1pt">A<span>&#x301;</span></p>');
    }

    public function testIndentThatConsumesAvailableWidthIsExplicitlyRejected(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('indentation');
        $this->layout('<p style="width:100pt;text-indent:100%">alpha</p>');
    }

    #[DataProvider('collapsedSpaces')]
    public function testCollapsedSpaceKeepsFirstOwner(float $first, float $second): void
    {
        $texts = $this->texts($this->layout('<p><span style="word-spacing:' . $first . 'pt">A </span><span style="word-spacing:' . $second . 'pt"> B</span></p>'));
        self::assertSame(['A ', 'B'], array_column($texts, 'text'));
        self::assertEqualsWithDelta(20 + self::$font->measure('A ', 10, new TextSpacing(wordSpacing: $first)), $texts[1]->x, 0.00001);
    }

    public static function collapsedSpaces(): iterable
    {
        yield [20.0, 0.0];
        yield [0.0, 20.0];
    }

    public function testLeadingAndTrailingCollapsedSpacesStayInvisible(): void
    {
        $texts = $this->texts($this->layout('<p> <span style="word-spacing:20pt"> A </span> </p>'));
        self::assertSame(['A'], array_column($texts, 'text'));
        self::assertSame(20.0, $texts[0]->x);
    }

    public function testWordAcrossSpansMovesTogetherAtPreviousSpace(): void
    {
        $texts = $this->texts($this->layout('<p style="width:35pt;letter-spacing:2pt">AA B<span>C</span>D</p>'));
        self::assertSame(['AA', 'B', 'C', 'D'], array_column($texts, 'text'));
        self::assertSame($texts[1]->y, $texts[2]->y);
        self::assertSame($texts[1]->y, $texts[3]->y);
        self::assertGreaterThan($texts[0]->y, $texts[1]->y);
        self::assertSame(20.0, $texts[1]->x);
    }

    public function testFirstWordAcrossSpansMovesBelowFloatAsAWhole(): void
    {
        $prefix = '<div style="float:left;width:150pt;height:24pt"></div><p style="width:180pt;letter-spacing:2pt">';
        $plain = $this->texts($this->layout($prefix . 'AAAAAA</p>'));
        $split = $this->texts($this->layout($prefix . '<span>AAA</span><span>AAA</span></p>'));
        self::assertCount(2, $split);
        self::assertSame($plain[0]->x, $split[0]->x);
        self::assertSame($plain[0]->y, $split[0]->y);
        self::assertSame($split[0]->y, $split[1]->y);
    }

    public function testLineMovedBelowFloatRetainsAvailableWidthForLaterWords(): void
    {
        $prefix = '<div style="float:left;width:150pt;height:24pt"></div><p style="width:180pt;letter-spacing:2pt">';
        foreach (['AAAAAA B', '<span>AAA</span><span>AAA</span> B'] as $html) {
            $texts = $this->texts($this->layout($prefix . $html . '</p>'));
            self::assertGreaterThan(44.0, $texts[0]->y);
            self::assertCount(1, array_unique(array_column($texts, 'y')));
        }
    }

    public function testUnbreakableWordAcrossSpansIsNotSplit(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('unbreakable');
        $this->layout('<p style="width:16pt;letter-spacing:2pt">A<span>B</span>C</p>');
    }

    #[DataProvider('tabSpacing')]
    public function testPreservedTabWithSpacingIsExplicitlyRejected(string $property): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('tab-stop');
        $this->layout('<p style="white-space:pre;' . $property . ':2pt">A' . "\t" . 'B</p>');
    }

    public static function tabSpacing(): iterable
    {
        yield ['word-spacing'];
        yield ['letter-spacing'];
    }

    private function layout(string $html, ?PageSettings $page = null): PagedDocument
    {
        $options = new ConversionOptions(new FontSet(self::$font), $page ?? new PageSettings(240, 400, new Edges(20, 20, 20, 20)));
        $diagnostics = new Diagnostics();
        $document = (new HtmlCompiler())->parse(new HtmlSource('<style>*{margin:0;padding:0}body{font-size:10pt;line-height:12pt}</style>' . $html), $options, $diagnostics);
        $result = (new LayoutEngine())->layout($document, $options, $diagnostics);
        self::assertSame([], $diagnostics->all());
        return $result;
    }

    /** @return list<Text> */
    private function texts(PagedDocument $document, ?int $page = null): array
    {
        $commands = $page === null ? array_merge(...array_column($document->pages, 'commands')) : $document->pages[$page]->commands;
        return array_values(array_filter($commands, static fn($command): bool => $command instanceof Text));
    }
}
