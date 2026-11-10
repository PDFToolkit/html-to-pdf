<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Layout\PagedDocument;
use PDF\HtmlToPdf\Model\HtmlDocument;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\Length;
use PDF\HtmlToPdf\Style\TextSpacing;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\TestCase;

final class LayoutEngineTest extends TestCase
{
    private static FontFace $font;
    private int $id = 0;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    public function testWhitespaceAcrossInlineElementsAndReadingOrderArePreserved(): void
    {
        $style = $this->style();
        $span = new Node('em', 'em', children: [$this->text(' beta ', $style)], style: $this->style(display: 'inline'));
        $p = new Node('paragraph', 'p', children: [$this->text("  alpha\n", $style), $span, $this->text(' gamma  ', $style)], style: $style);
        $result = $this->layout([$p]);
        self::assertSame('alpha beta gamma', implode('', array_map(static fn(Text $text): string => $text->text, $this->texts($result))));
        self::assertSame(['Document', 'P', 'Span', 'Span', 'Span', 'Span'], array_column($result->semantics, 'role'));
        $order = array_flip(array_column($result->semantics, 'id'));
        $positions = array_map(static fn(Text $text): int => $order[$text->semanticId], $this->texts($result));
        self::assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
    }

    public function testShapedWidthMatchesTextAndAlignment(): void
    {
        $s = $this->style(textAlign: 'right');
        $text = 'office AV été';
        $result = $this->layout([$this->paragraph($text, $s)]);
        $command = $this->texts($result)[0];
        self::assertSame($text, $command->text);
        self::assertEqualsWithDelta(190 - self::$font->measure($text, 10), $command->x, 0.001);
        self::assertGreaterThan(10, $command->y);
    }

    public function testPreformattedNewlinesAndSpacesSurvive(): void
    {
        $s = $this->style(whiteSpace: 'pre');
        $result = $this->layout([$this->paragraph("  alpha\n beta  ", $s)]);
        $texts = $this->texts($result);
        self::assertSame(['  alpha', ' beta  '], array_column($texts, 'text'));
        self::assertEqualsWithDelta(16, $texts[1]->y - $texts[0]->y, 0.001);
    }

    public function testBoldVariantUsesExplicitFaceAndMissingVariantFails(): void
    {
        $s = $this->style(fontWeight: 700);
        $this->expectException(UnsupportedFeature::class);
        $this->layout([$this->paragraph('Bold', $s)]);
    }

    public function testExplicitBoldAndItalicVariantIsRetained(): void
    {
        $face = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
        $s = $this->style(fontWeight: 700, italic: true);
        $options = new ConversionOptions(new FontSet(self::$font, boldItalic: $face));
        $result = $this->layout([$this->paragraph('Variant', $s)], $options);
        self::assertSame($face, $this->texts($result)[0]->font);
    }

    public function testWidowsMoveOneLineToTheNextPage(): void
    {
        $s = $this->style(whiteSpace: 'pre');
        $result = $this->layout([$this->paragraph("a\nb", $s), $this->paragraph("one\ntwo\nthree\nfour", $s)], $this->options(height: 100));
        self::assertCount(2, $result->pages);
        self::assertSame(['a', 'b', 'one', 'two'], array_column($this->pageTexts($result, 0), 'text'));
        self::assertSame(['three', 'four'], array_column($this->pageTexts($result, 1), 'text'));
    }

    public function testOrphansMoveTheParagraphBeforeItsFirstLine(): void
    {
        $s = $this->style(whiteSpace: 'pre');
        $result = $this->layout([$this->paragraph("a\nb\nc\nd", $s), $this->paragraph("one\ntwo\nthree", $s)], $this->options(height: 100));
        self::assertSame(['a', 'b', 'c', 'd'], array_column($this->pageTexts($result, 0), 'text'));
        self::assertSame(['one', 'two', 'three'], array_column($this->pageTexts($result, 1), 'text'));
    }

    public function testAvoidAndExplicitPageBreakDoNotCreateTrailingBlankPages(): void
    {
        $s = $this->style(whiteSpace: 'pre');
        $keep = $this->style(whiteSpace: 'pre', breakInsideAvoid: true, breakAfter: 'page');
        $result = $this->layout([$this->paragraph("a\nb\nc", $s), $this->paragraph("one\ntwo\nthree", $keep)], $this->options(height: 100));
        self::assertCount(2, $result->pages);
        self::assertSame(['one', 'two', 'three'], array_column($this->pageTexts($result, 1), 'text'));
    }

    public function testSiblingMarginsCollapseToTheirMaximum(): void
    {
        $first = $this->style(margin: new Edges(bottom: 12));
        $second = $this->style(margin: new Edges(top: 20));
        $result = $this->layout([$this->paragraph('first', $first), $this->paragraph('second', $second)]);
        $texts = $this->texts($result);
        self::assertEqualsWithDelta(36, $texts[1]->y - $texts[0]->y, 0.001);
    }

    public function testParentChildMarginsPropagateOutsideTheBackground(): void
    {
        $child = $this->paragraph('inside', $this->style(margin: new Edges(30, 0, 40, 0)));
        $parent = new Node('parent', 'div', children: [$child], style: $this->style(background: new Color(1, 0, 0), margin: new Edges(20, 0, 10, 0)));
        $diagnostics = new Diagnostics();
        $result = (new LayoutEngine())->layout($this->document([$parent, $this->paragraph('after')]), $this->options(), $diagnostics);
        $texts = $this->texts($result);
        self::assertEqualsWithDelta(56, $texts[1]->y - $texts[0]->y, 0.001);
        $background = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle && $command->color?->red === 1.0));
        self::assertCount(1, $background);
        self::assertSame(40.0, $background[0]->y);
        self::assertSame(16.0, $background[0]->height);
        self::assertSame([], $diagnostics->all());
    }

    public function testPaddingPreventsParentChildTopMarginCollapse(): void
    {
        $child = $this->paragraph('inside', $this->style(margin: new Edges(top: 30)));
        $parent = new Node('parent', 'div', children: [$child], style: $this->style(margin: new Edges(top: 20), padding: new Edges(top: 5)));
        $reference = $this->texts($this->layout([$this->paragraph('inside')]))[0];
        $actual = $this->texts($this->layout([$parent]))[0];
        self::assertEqualsWithDelta(55, $actual->y - $reference->y, 0.001);
    }

    public function testMarginsCollapseThroughAnEmptyBlock(): void
    {
        $first = $this->paragraph('first', $this->style(margin: new Edges(bottom: 10)));
        $empty = new Node('empty', 'div', style: $this->style(margin: new Edges(20, 0, 40, 0)));
        $last = $this->paragraph('last', $this->style(margin: new Edges(top: 30)));
        $texts = $this->texts($this->layout([$first, $empty, $last]));
        self::assertEqualsWithDelta(56, $texts[1]->y - $texts[0]->y, 0.001);
    }

    public function testEmptyParentsPropagateTheLargestDescendantMargin(): void
    {
        $child = new Node('empty-child', 'div', style: $this->style(margin: new Edges(30, 0, 40, 0)));
        $parent = new Node('empty-parent', 'div', children: [$child], style: $this->style(margin: new Edges(10, 0, 20, 0)));
        $reference = $this->texts($this->layout([$this->paragraph('after')]))[0];
        $actual = $this->texts($this->layout([$parent, $this->paragraph('after')]))[0];
        self::assertEqualsWithDelta(40, $actual->y - $reference->y, 0.001);
    }

    public function testFixedHeightStopsBottomMarginPropagation(): void
    {
        $child = $this->paragraph('inside', $this->style(margin: new Edges(bottom: 20)));
        $parent = new Node('parent', 'div', children: [$child], style: $this->style(height: new Length(80), margin: new Edges(bottom: 10)));
        $texts = $this->texts($this->layout([$parent, $this->paragraph('after')]));
        self::assertEqualsWithDelta(90, $texts[1]->y - $texts[0]->y, 0.001);
    }

    public function testBoxPaddingBackgroundAndBordersStayWithinPage(): void
    {
        $s = $this->style(background: new Color(1, 0, 0), padding: new Edges(4, 6, 8, 10), borderWidth: 2);
        $result = $this->layout([$this->paragraph('Box', $s)]);
        self::assertEqualsWithDelta(22, $this->texts($result)[0]->x, 0.001);
        $rectangles = array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle);
        self::assertNotEmpty($rectangles);
        foreach ($rectangles as $rectangle) {
            self::assertGreaterThanOrEqual(10, $rectangle->x);
            self::assertLessThanOrEqual(190.001, $rectangle->x + $rectangle->width);
        }
    }

    public function testListMarkersHaveSeparateLogicalLabelsAndBodies(): void
    {
        $liStyle = $this->style(display: 'list-item', listStyleType: 'decimal');
        $list = new Node('list', 'ol', attributes: ['start' => '3'], children: [new Node('li1', 'li', children: [$this->text('First', $liStyle)], style: $liStyle), new Node('li2', 'li', children: [$this->text('Second', $liStyle)], style: $liStyle)], style: $this->style());
        $result = $this->layout([$list]);
        self::assertSame(['3.', 'First', '4.', 'Second'], array_column($this->texts($result), 'text'));
        self::assertSame(['Document', 'L', 'LI', 'Lbl', 'LBody', 'Span', 'LI', 'Lbl', 'LBody', 'Span'], array_column($result->semantics, 'role'));
    }

    public function testDiscStyleOverridesTheOrderedListDefaultMarker(): void
    {
        $style = $this->style(display: 'list-item', listStyleType: 'disc');
        $list = new Node('list', 'ol', children: [new Node('item', 'li', children: [$this->text('First', $style)], style: $style)], style: $this->style());
        self::assertSame(['•', 'First'], array_column($this->texts($this->layout([$list])), 'text'));
    }

    public function testAnExhaustedListCounterCannotBecomeScientificNotation(): void
    {
        $style = $this->style(display: 'list-item', listStyleType: 'decimal');
        $list = new Node('list', 'ol', ['start' => (string) PHP_INT_MAX], [new Node('first', 'li', children: [$this->text('First', $style)], style: $style), new Node('second', 'li', children: [$this->text('Second', $style)], style: $style)], style: $this->style());
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('List counter');
        $this->layout([$list]);
    }

    public function testTableHeaderRepeatsAsArtifactAndRowsStayWhole(): void
    {
        $result = $this->layout([$this->table(7)], $this->options(height: 100));
        self::assertCount(2, $result->pages);
        $all = $this->texts($result);
        self::assertCount(9, $all);
        $headers = array_values(array_filter($all, static fn(Text $text): bool => $text->text === 'Header'));
        self::assertCount(2, $headers);
        self::assertNotNull($headers[0]->semanticId);
        self::assertNull($headers[1]->semanticId);
        self::assertNull($headers[1]->uri);
        self::assertSame('Header', $this->pageTexts($result, 1)[0]->text);
        self::assertCount(1, array_filter($result->semantics, static fn($node): bool => $node->role === 'TH'));
        foreach (range(1, 7) as $number) {
            self::assertCount(1, array_filter($all, static fn(Text $text): bool => $text->text === 'Row ' . $number));
        }
    }

    public function testRepeatedHeaderArtifactKeepsTheSpacingItWasMeasuredWith(): void
    {
        $spaced = $this->style(textSpacing: new TextSpacing(letterSpacing: 2.0, wordSpacing: 3.0));
        $header = new Node('th', 'th', children: [$this->text('Head er', $spaced)], style: $this->style(display: 'table-cell'));
        $rows = [];
        foreach (range(1, 7) as $index) {
            $rows[] = new Node('row-' . $index, 'tr', children: [new Node('cell-' . $index, 'td', children: [$this->text('Row ' . $index)], style: $this->style(display: 'table-cell'))], style: $this->style(display: 'table-row'));
        }
        $table = new Node('table', 'table', children: [new Node('head', 'thead', children: [new Node('header-row', 'tr', children: [$header], style: $this->style(display: 'table-row'))], style: $this->style(display: 'table-header-group')), new Node('rows', 'tbody', children: $rows, style: $this->style(display: 'table-row-group'))], style: $this->style(display: 'table', tableLayout: 'fixed'));
        $result = $this->layout([$table], $this->options(height: 100));
        $headers = array_values(array_filter($this->texts($result), static fn(Text $text): bool => $text->text === 'Head er'));
        self::assertCount(2, $headers);
        self::assertNull($headers[1]->semanticId);
        self::assertEquals(new TextSpacing(2.0, 3.0), $headers[0]->spacing);
        self::assertEquals($headers[0]->spacing, $headers[1]->spacing);
        self::assertSame($headers[0]->font->measure('Head er', $headers[0]->size, $headers[0]->spacing), $headers[1]->font->measure('Head er', $headers[1]->size, $headers[1]->spacing));
    }

    public function testHiddenRowsInsideBothHeadAndBodyDoNotPaintOrCreateStructure(): void
    {
        $hiddenHead = new Node('hidden-head', 'tr', children: [new Node('hidden-th', 'th', children: [$this->text('HIDDEN HEADER')], style: $this->style(display: 'table-cell'))], style: $this->style(display: 'none'));
        $hiddenBody = new Node('hidden-body', 'tr', children: [new Node('hidden-td', 'td', children: [$this->text('HIDDEN BODY')], style: $this->style(display: 'table-cell'))], style: $this->style(display: 'none'));
        $visible = new Node('visible', 'tr', children: [new Node('visible-td', 'td', children: [$this->text('Visible')], style: $this->style(display: 'table-cell'))], style: $this->style(display: 'table-row'));
        $table = new Node('table', 'table', children: [new Node('head', 'thead', children: [$hiddenHead], style: $this->style(display: 'table-header-group')), new Node('body-group', 'tbody', children: [$hiddenBody, $visible], style: $this->style(display: 'table-row-group'))], style: $this->style(display: 'table', tableLayout: 'fixed'));
        $result = $this->layout([$table]);
        self::assertSame(['Visible'], array_column($this->texts($result), 'text'));
        self::assertCount(1, array_filter($result->semantics, static fn($node): bool => $node->role === 'TR'));
        self::assertNotContains('TH', array_column($result->semantics, 'role'));
    }

    public function testBlankPreformattedLinesAreBoundedBeforeDrawing(): void
    {
        $style = $this->style(whiteSpace: 'pre');
        $options = new ConversionOptions(new FontSet(self::$font), limits: new ConversionLimits(maxCommands: 4));
        $this->expectException(LimitExceeded::class);
        $this->layout([$this->paragraph(str_repeat("\n", 30), $style)], $options);
    }

    public function testAutomaticTablesRequireAnExplicitPolicyDecision(): void
    {
        $table = $this->table(1, fixed: false);
        $this->expectException(UnsupportedFeature::class);
        $this->layout([$table]);
    }

    public function testReportedAutomaticTableFallbackIsVisible(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $result = (new LayoutEngine())->layout($this->document([$this->table(1, fixed: false)]), $this->options(), $diagnostics);
        self::assertCount(1, $result->pages);
        self::assertContains('layout.table-auto', array_column($diagnostics->all(), 'code'));
    }

    public function testOversizedTableRowFailsWithoutLooping(): void
    {
        $s = $this->style(display: 'table-cell', whiteSpace: 'pre');
        $cell = new Node('cell', 'td', children: [$this->text(implode("\n", range(1, 10)), $s)], style: $s);
        $table = new Node('table', 'table', children: [new Node('row', 'tr', children: [$cell], style: $this->style(display: 'table-row'))], style: $this->style(display: 'table', tableLayout: 'fixed'));
        $this->expectException(UnsupportedFeature::class);
        $this->layout([$table], $this->options(height: 100));
    }

    public function testTooWideUnbreakableTextIsRejected(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->layout([$this->paragraph(str_repeat('W', 100))]);
    }

    public function testPageAndCommandBudgetsAreEnforced(): void
    {
        $s = $this->style(breakAfter: 'page');
        $this->expectException(LimitExceeded::class);
        $this->layout([$this->paragraph('first', $s), $this->paragraph('second', $s)], new ConversionOptions(new FontSet(self::$font), limits: new ConversionLimits(maxPages: 1)));
    }

    public function testRepeatedHeadersCountAgainstCommandBudget(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->layout([$this->table(7)], new ConversionOptions(new FontSet(self::$font), page: new PageSettings(200, 100, new Edges(10, 10, 10, 10)), limits: new ConversionLimits(maxCommands: 8)));
    }

    public function testImagesUseOnlyExplicitResourcesAndKeepAlt(): void
    {
        $image = new ImageAsset(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        $node = new Node('image', 'img', ['src' => 'asset:logo', 'alt' => 'Logo'], style: $this->style(display: 'inline', width: new Length(20)));
        $options = new ConversionOptions(new FontSet(self::$font), resources: new ResourceCatalog(['asset:logo' => $image]));
        $result = $this->layout([$node], $options);
        $commands = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Image));
        self::assertCount(1, $commands);
        self::assertSame($image, $commands[0]->asset);
        self::assertSame(20.0, $commands[0]->width);
        self::assertSame('Logo', array_values(array_filter($result->semantics, static fn($node): bool => $node->role === 'Figure'))[0]->alt);
    }

    public function testNewDocumentsDoNotRetainPriorState(): void
    {
        $engine = new LayoutEngine();
        $one = $engine->layout($this->document([$this->paragraph('one')]), $this->options(), new Diagnostics());
        $two = $engine->layout($this->document([$this->paragraph('two')]), $this->options(), new Diagnostics());
        self::assertSame(['two'], array_column($this->texts($two), 'text'));
        self::assertSame($one->semantics[0]->id, $two->semantics[0]->id);
    }

    public function testLinkTextHasAnOrderedLeafInsideItsLinkContainer(): void
    {
        $s = $this->style(display: 'inline');
        $link = new Node('anchor', 'a', ['href' => 'https://example.test/'], [$this->text('link', $s)], style: $s);
        $paragraph = new Node('paragraph', 'p', children: [$this->text('before ', $s), $link, $this->text(' after', $s)], style: $this->style());
        $result = $this->layout([$paragraph]);
        $texts = $this->texts($result);
        $linked = array_values(array_filter($texts, static fn(Text $text): bool => $text->uri !== null));
        self::assertCount(1, $linked);
        self::assertSame('link', $linked[0]->text);
        $semantics = array_column($result->semantics, null, 'id');
        $leaf = $semantics[$linked[0]->semanticId];
        self::assertSame('Span', $leaf->role);
        self::assertSame('Link', $semantics[$leaf->parentId]->role);
        self::assertSame('before link after', implode('', array_column($texts, 'text')));
    }

    public function testBlockImagePercentIsResolvedOnceAndEmptyAltIsArtifact(): void
    {
        $asset = new ImageAsset(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        $node = new Node('image', 'img', ['src' => 'logo', 'alt' => ''], style: $this->style(width: new Length(50, true)));
        $options = new ConversionOptions(new FontSet(self::$font), new PageSettings(200, 200, new Edges(10, 10, 10, 10)), new ResourceCatalog(['logo' => $asset]));
        $result = $this->layout([$node, $this->paragraph('after')], $options);
        $image = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Image))[0];
        self::assertSame(90.0, $image->width);
        self::assertSame(90.0, $image->height);
        self::assertSame(10.0, $image->y);
        self::assertNull($image->semanticId);
        self::assertNotContains('Figure', array_column($result->semantics, 'role'));
        self::assertGreaterThan(100, $this->texts($result)[0]->y);
    }

    public function testCellBackgroundSpansTallRowAndBottomAlignmentUsesItsHeight(): void
    {
        $s = $this->style(display: 'table-cell', whiteSpace: 'pre');
        $second = $this->style(display: 'table-cell', background: new Color(1, 0, 0), verticalAlign: 'bottom');
        $row = new Node('row', 'tr', children: [new Node('left', 'td', children: [$this->text("a\nb\nc", $s)], style: $s), new Node('right', 'td', children: [$this->text('bottom', $second)], style: $second)], style: $this->style(display: 'table-row'));
        $table = new Node('table', 'table', children: [$row], style: $this->style(display: 'table', tableLayout: 'fixed'));
        $result = $this->layout([$table]);
        $rectangles = array_values(array_filter($result->pages[0]->commands, static fn($command): bool => $command instanceof Rectangle && $command->color?->red === 1.0));
        self::assertNotEmpty($rectangles);
        self::assertSame(48.0, $rectangles[0]->height);
        $texts = $this->texts($result);
        self::assertEqualsWithDelta($texts[2]->y, $texts[3]->y, 0.001);
    }

    public function testExplicitHeaderScopeIsTranslatedToPdfScope(): void
    {
        $cell = new Node('head', 'th', ['scope' => 'col'], [$this->text('Heading')], style: $this->style(display: 'table-cell'));
        $table = new Node('table', 'table', children: [new Node('row', 'tr', children: [$cell], style: $this->style(display: 'table-row'))], style: $this->style(display: 'table', tableLayout: 'fixed'));
        $result = $this->layout([$table]);
        $headers = array_values(array_filter($result->semantics, static fn($node): bool => $node->role === 'TH'));
        self::assertSame('Column', $headers[0]->scope);
    }

    private function style(mixed ...$values): ComputedStyle
    {
        return new ComputedStyle(...array_replace(['display' => 'block', 'fontSize' => 10, 'lineHeight' => 16], $values));
    }

    private function text(string $text, ?ComputedStyle $style = null): Node
    {
        return new Node('text-' . ++$this->id, '#text', text: $text, style: $style ?? $this->style());
    }

    private function paragraph(string $text, ?ComputedStyle $style = null): Node
    {
        $style ??= $this->style();

        return new Node('p-' . ++$this->id, 'p', children: [$this->text($text, $style)], style: $style);
    }

    /** @param list<Node> $nodes */
    private function document(array $nodes): HtmlDocument
    {
        return new HtmlDocument(new Node('body', 'body', children: $nodes, style: $this->style()));
    }

    /** @param list<Node> $nodes */
    private function layout(array $nodes, ?ConversionOptions $options = null): PagedDocument
    {
        return (new LayoutEngine())->layout($this->document($nodes), $options ?? $this->options(), new Diagnostics());
    }

    private function options(float $height = 200): ConversionOptions
    {
        return new ConversionOptions(new FontSet(self::$font), new PageSettings(200, $height, new Edges(10, 10, 10, 10)));
    }

    private function table(int $rowCount, bool $fixed = true): Node
    {
        $header = new Node('th', 'th', children: [$this->text('Header')], style: $this->style(display: 'table-cell'));
        $rows = [];
        foreach (range(1, $rowCount) as $index) {
            $rows[] = new Node('row-' . $index, 'tr', children: [new Node('cell-' . $index, 'td', children: [$this->text('Row ' . $index)], style: $this->style(display: 'table-cell'))], style: $this->style(display: 'table-row'));
        }

        return new Node('table', 'table', children: [new Node('head', 'thead', children: [new Node('header-row', 'tr', children: [$header], style: $this->style(display: 'table-row'))], style: $this->style(display: 'table-header-group')), new Node('rows', 'tbody', children: $rows, style: $this->style(display: 'table-row-group'))], style: $this->style(display: 'table', tableLayout: $fixed ? 'fixed' : 'auto'));
    }

    /** @return list<Text> */
    private function texts(PagedDocument $document): array
    {
        $texts = [];
        foreach ($document->pages as $index => $page) {
            array_push($texts, ...$this->pageTexts($document, $index));
        }

        return $texts;
    }

    /** @return list<Text> */
    private function pageTexts(PagedDocument $document, int $index): array
    {
        return array_values(array_filter($document->pages[$index]->commands, static fn($command): bool => $command instanceof Text));
    }
}
