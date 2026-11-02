<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Parsing;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\FormControls;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Parsing\HtmlDoctypeProjection;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class StaticDocumentTest extends CompilerTestCase
{
    public function testDefinitionListKeepsHtmlRolesAndDefaultBlockBoxes(): void
    {
        $document = $this->compile('<dl id="list"><dt id="term">Term</dt><dd id="definition">Definition</dd></dl>');
        foreach (['list' => 'dl', 'term' => 'dt', 'definition' => 'dd'] as $id => $tag) {
            $node = $this->find($document->root, $id);
            self::assertSame($tag, $node->tag);
            self::assertSame('block', $node->style->display);
        }
    }

    public function testStaticSnapshotIsExplicitAndPreservesRadioStateWithoutAnAction(): void
    {
        $document = $this->compile('<form id="form" action="https://unused.invalid/submit" method="post"><p>Choice<input id="choice" type="RADIO" name="choice" value="yes" checked disabled></p></form>', 'form{display:inline}form p{line-height:1.9}', $this->snapshotOptions());
        $form = $this->find($document->root, 'form');
        self::assertSame('inline', $form->style->display);
        self::assertNull($form->attribute('action'));
        self::assertNull($form->attribute('method'));
        $radio = $this->find($document->root, 'choice');
        self::assertSame('input', $radio->tag);
        self::assertSame('radio', $radio->attribute('type'));
        self::assertSame('choice', $radio->attribute('name'));
        self::assertSame('yes', $radio->attribute('value'));
        self::assertNotNull($radio->attribute('checked'));
        self::assertNotNull($radio->attribute('disabled'));
        self::assertEqualsWithDelta(22.8, $radio->style->lineHeight, 0.000001);
    }

    public function testDefaultPolicyStillRejectsFormsAndControls(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<input type="radio">');
    }

    public function testSnapshotDoesNotImplySupportForOtherControls(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<input type="text">', options: $this->snapshotOptions());
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedRadioStyles(): iterable
    {
        foreach (['display:block', 'width:10px', 'height:10px', 'float:left', 'clear:both', 'margin:1px', 'padding:1px', 'border:1px solid black', 'background:red'] as $style) {
            yield $style => [$style];
        }
    }

    #[DataProvider('unsupportedRadioStyles')]
    public function testUnsupportedRadioBoxStylesAreRejected(string $style): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<input type="radio" style="' . $style . '">', options: $this->snapshotOptions());
    }

    public function testReportModeDropsRadioWithUnsupportedBoxStyles(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $options = new ConversionOptions($this->options()->fonts, unsupported: UnsupportedPolicy::Report, formControls: FormControls::StaticSnapshot);
        $document = $this->compile('<p><input type="radio" style="display:block">Text</p>', options: $options, diagnostics: $diagnostics);
        self::assertSame('html.form-style', $diagnostics->all()[0]->code);
        self::assertCount(1, $document->root->children[0]->children);
        self::assertSame('Text', $document->root->children[0]->children[0]->text);
    }

    public function testHiddenRadioDoesNotNeedUnsupportedBoxGeometry(): void
    {
        $document = $this->compile('<input id="radio" type="radio" style="display:none;width:30px">', options: $this->snapshotOptions());
        self::assertSame('none', $this->find($document->root, 'radio')->style->display);
    }

    public function testReportModeKeepsUnsupportedControlsVisibleAsDiagnostics(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $options = new ConversionOptions($this->options()->fonts, unsupported: UnsupportedPolicy::Report, formControls: FormControls::StaticSnapshot);
        $document = $this->compile('<form><input type="text"><p id="remaining">Text</p></form>', options: $options, diagnostics: $diagnostics);
        self::assertSame('html.form-control', $diagnostics->all()[0]->code);
        self::assertSame('Text', $this->find($document->root, 'remaining')->children[0]->text);
        self::assertCount(1, $document->root->children[0]->children);
    }

    public function testRootBackgroundWinsAndBodyBackgroundKeepsItsOwnBox(): void
    {
        $document = $this->compile('<html><body><p>Text</p></body></html>', 'html{background:blue}body{background:white}');
        self::assertEquals(new Color(0, 0, 1), $document->canvasBackground);
        self::assertEquals(new Color(1, 1, 1), $document->root->style->background);
    }

    public function testTransparentRootPropagatesBodyBackgroundToCanvasOnly(): void
    {
        $document = $this->compile('<p>Text</p>', 'body{background:white;margin:20px}');
        self::assertEquals(new Color(1, 1, 1), $document->canvasBackground);
        self::assertNull($document->root->style->background);
        self::assertSame(15.0, $document->root->style->margin->top);
    }

    public function testHiddenRootDoesNotPaintACanvasBackground(): void
    {
        $document = $this->compile('<html style="display:none;background:blue"><body style="background:red">Hidden</body></html>');
        self::assertNull($document->canvasBackground);
        self::assertSame('none', $document->root->style->display);
    }

    public function testExplicitSourceBaseResolvesRelativeLinksWithoutDroppingFragments(): void
    {
        $source = new HtmlSource('<p><a id="a" href="../reference.htm?x=1#part">Reference</a><a id="b" href="#part">Part</a></p>', baseUri: 'https://example.test/tests/source.htm');
        $document = (new HtmlCompiler())->parse($source, $this->options(), new Diagnostics());
        self::assertSame('https://example.test/reference.htm?x=1#part', $this->find($document->root, 'a')->attribute('href'));
        self::assertSame('https://example.test/tests/source.htm#part', $this->find($document->root, 'b')->attribute('href'));
    }

    /** @return iterable<string,array{string}> */
    public static function strictLegacyDoctypes(): iterable
    {
        yield 'HTML4 strict' => ['<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0//EN" "http://www.w3.org/TR/REC-html40/strict.dtd">'];
        yield 'HTML4.01 strict' => ["<!doctype html PUBLIC '-//W3C//DTD HTML 4.01//EN' 'http://www.w3.org/TR/html4/strict.dtd'>"];
        yield 'newlines and comment prefix' => ["<!-- metadata -->\n<!DOCTYPE\nHTML PUBLIC\n\"-//W3C//DTD HTML 4.0//EN\"\n\"http://www.w3.org/TR/REC-html40/strict.dtd\">\n"];
    }

    #[DataProvider('strictLegacyDoctypes')]
    public function testRecognizedHtml4StrictMetadataDoesNotCauseSpuriousParseErrors(string $doctype): void
    {
        $html = $doctype . '<html><body><p id="text">Text</p></body></html>';
        $projected = (new HtmlDoctypeProjection())->project($html);
        self::assertSame(strlen($html), strlen($projected));
        self::assertSame(substr_count($html, "\n"), substr_count($projected, "\n"));
        $diagnostics = new Diagnostics();
        $document = $this->compile($html, diagnostics: $diagnostics);
        self::assertSame([], $diagnostics->all());
        self::assertSame('Text', $this->find($document->root, 'text')->children[0]->text);
    }

    /** @return iterable<string,array{string}> */
    public static function unrecognizedDoctypes(): iterable
    {
        yield 'unmatched identifier pair' => ['<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0//EN" "http://www.w3.org/TR/html4/strict.dtd"><p>Text</p>'];
        yield 'malformed extra token' => ['<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0//EN" "http://www.w3.org/TR/REC-html40/strict.dtd" junk><p>Text</p>'];
        yield 'unknown public ID' => ['<!DOCTYPE HTML PUBLIC "unknown" "http://unused.invalid/example.dtd"><p>Text</p>'];
        yield 'later doctype' => ['<p>Text</p><!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0//EN" "http://www.w3.org/TR/REC-html40/strict.dtd">'];
    }

    #[DataProvider('unrecognizedDoctypes')]
    public function testMalformedOrUnrecognizedDeclarationsAreNotSilentlyRewritten(string $html): void
    {
        self::assertSame($html, (new HtmlDoctypeProjection())->project($html));
        $diagnostics = new Diagnostics();
        $this->compile($html, diagnostics: $diagnostics);
        self::assertNotEmpty($diagnostics->all());
        self::assertSame('html.parse', $diagnostics->all()[0]->code);
    }

    private function snapshotOptions(): ConversionOptions
    {
        return new ConversionOptions($this->options()->fonts, formControls: FormControls::StaticSnapshot);
    }
}
