<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostic;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\InlineFormatter;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\FontSlant;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\TestCase;

/** CSS1 5.2.1 font matching as observed in the laid-out text commands. */
final class FontMatchingLayoutTest extends TestCase
{
    private static ?FontFace $noto = null;
    private static ?FontFace $lato = null;
    private static ?FontFace $latoBold = null;
    private static ?FontFace $latoItalic = null;
    private static ?FontFace $latoBoldItalic = null;

    public static function setUpBeforeClass(): void
    {
        self::$noto = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
        foreach (['lato' => 'Regular', 'latoBold' => 'Bold', 'latoItalic' => 'Italic', 'latoBoldItalic' => 'BoldItalic'] as $property => $file) {
            self::${$property} = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-' . $file . '.ttf'));
        }
    }

    /** Prepared layout tables are large: release them with the class. */
    public static function tearDownAfterClass(): void
    {
        self::$noto = null;
        self::$lato = null;
        self::$latoBold = null;
        self::$latoItalic = null;
        self::$latoBoldItalic = null;
    }

    public function testEachWeightUsesTheFaceTheSpecificationAssigns(): void
    {
        $fonts = new FontSet(self::$lato, bold: self::$latoBold);
        $html = '';
        foreach (range(100, 900, 100) as $weight) {
            $html .= '<p style="font-weight:' . $weight . '">w' . $weight . '</p>';
        }
        [$faces, $diagnostics] = $this->faces($html, $fonts);
        foreach (range(100, 900, 100) as $weight) {
            self::assertSame($weight <= 500 ? self::$lato : self::$latoBold, $faces['w' . $weight], 'weight ' . $weight);
        }
        // Both witnesses agree: 100 to 500 use the 400 face and 600 to 900 the 700 face. No request crosses the threshold.
        self::assertSame([], $diagnostics);
    }

    public function testAdditionalLabeledFacesTakePartInMatching(): void
    {
        $fonts = new FontSet(self::$lato, bold: self::$latoBold, faces: [new FamilyFace(self::$noto, 300), new FamilyFace(self::$latoBoldItalic, 900)]);
        [$faces] = $this->faces('<p style="font-weight:200">light</p><p style="font-weight:800">black</p><p style="font-weight:600">bold</p><p>regular</p>', $fonts);
        self::assertSame(self::$noto, $faces['light']);
        self::assertSame(self::$latoBoldItalic, $faces['black']);
        self::assertSame(self::$latoBold, $faces['bold']);
        self::assertSame(self::$lato, $faces['regular']);
    }

    public function testStyleIsMatchedBeforeWeightAndElementsKeepTheirOwnFaces(): void
    {
        $fonts = new FontSet(self::$lato, self::$latoBold, self::$latoItalic, self::$latoBoldItalic);
        [$faces, $diagnostics] = $this->faces('<p>plain <em>slanted</em> <strong>strong</strong> <em><b>both</b></em></p>', $fonts);
        self::assertSame(self::$lato, $faces['plain']);
        self::assertSame(self::$latoItalic, $faces['slanted']);
        self::assertSame(self::$latoBold, $faces['strong']);
        self::assertSame(self::$latoBoldItalic, $faces['both']);
        self::assertSame([], $diagnostics);
    }

    public function testObliqueRequiresAFaceLabeledObliqueAndItalicAcceptsIt(): void
    {
        $oblique = new FamilyFace(self::$latoBoldItalic, 400, FontSlant::Oblique);
        $both = new FontSet(self::$lato, italic: self::$latoItalic, faces: [$oblique]);
        [$faces, $diagnostics] = $this->faces('<p><span style="font-style:oblique">slanted</span> <em>italic</em> upright</p>', $both);
        self::assertSame(self::$latoBoldItalic, $faces['slanted']);
        self::assertSame(self::$latoItalic, $faces['italic']);
        self::assertSame(self::$lato, $faces['upright']);
        self::assertSame([], $diagnostics);

        // Without an italic face, italic is satisfied by the oblique one (CSS1 5.2.3).
        [$faces, $diagnostics] = $this->faces('<p><em>italic</em></p>', new FontSet(self::$lato, faces: [$oblique]));
        self::assertSame(self::$latoBoldItalic, $faces['italic']);
        self::assertSame([], $diagnostics);
    }

    public function testObliqueIsNotSatisfiedByAnItalicFaceOfAListedFamily(): void
    {
        $oblique = new FamilyFace(self::$latoBoldItalic, 400, FontSlant::Oblique);
        $fonts = new FontSet(self::$noto, catalog: new FontCatalog(['ItalicOnly' => new FontSet(self::$lato, italic: self::$latoItalic), 'WithOblique' => new FontSet(self::$latoBold, faces: [$oblique])]));
        [$faces, $diagnostics] = $this->faces('<p style="font-family:ItalicOnly, WithOblique;font-style:oblique">slanted</p>', $fonts);
        self::assertSame(self::$latoBoldItalic, $faces['slanted'], 'the italic-only family fails on the style and the next family is tried');
        self::assertSame([], $diagnostics);

        // No family has an oblique face: the default family offers its closest face and says so.
        $default = new FontSet(self::$noto, italic: self::$latoItalic, catalog: new FontCatalog(['ItalicOnly' => new FontSet(self::$lato, italic: self::$latoBold)]));
        [$faces, $diagnostics] = $this->faces('<p style="font-family:ItalicOnly;font-style:oblique">slanted</p>', $default);
        self::assertSame(self::$latoItalic, $faces['slanted']);
        self::assertSame(['font.default-family', 'font.style-fallback'], array_map(static fn(Diagnostic $item): string => $item->code, $diagnostics));
        self::assertSame('Text requested in oblique style is drawn with a face labeled italic.', $diagnostics[1]->message);
    }

    public function testAFamilyWithoutTheStyleIsSkippedForTheNextFamily(): void
    {
        $fonts = new FontSet(self::$noto, catalog: new FontCatalog(['Plain' => new FontSet(self::$latoBold), 'Complete' => new FontSet(self::$lato, italic: self::$latoItalic)]));
        [$faces, $diagnostics] = $this->faces('<p style="font-family:Plain, Complete">upright <em>slanted</em></p>', $fonts);
        self::assertSame(self::$latoBold, $faces['upright']);
        self::assertSame(self::$latoItalic, $faces['slanted']);
        self::assertSame([], $diagnostics);
    }

    public function testUnboundFamiliesUseTheDefaultFamilyInBothPolicies(): void
    {
        foreach ([UnsupportedPolicy::Reject, UnsupportedPolicy::Report] as $policy) {
            [$faces, $diagnostics] = $this->faces('<p style="font-family:Verdana, serif">text</p><p style="font-family:Verdana,serif">again</p>', new FontSet(self::$noto), $policy);
            self::assertSame(self::$noto, $faces['text']);
            self::assertSame(self::$noto, $faces['again']);
            self::assertCount(1, $diagnostics, 'one note per distinct request');
            self::assertSame('font.default-family', $diagnostics[0]->code);
            self::assertStringContainsString('"Verdana", serif', $diagnostics[0]->message);
        }
    }

    public function testNoNoteWhenTheAuthorNamesNoFamily(): void
    {
        [$faces, $diagnostics] = $this->faces('<p>text</p>', new FontSet(self::$noto));
        self::assertSame(self::$noto, $faces['text']);
        self::assertSame([], $diagnostics);
    }

    public function testMissingStyleInTheDefaultFamilyUsesItsBestMatchAndIsReported(): void
    {
        [$faces, $diagnostics] = $this->faces('<p>plain <em>slanted</em></p>', new FontSet(self::$noto, bold: self::$latoBold));
        self::assertSame(self::$noto, $faces['slanted']);
        self::assertSame(['font.style-fallback'], array_map(static fn(Diagnostic $item): string => $item->code, $diagnostics));
        self::assertSame('Text requested in italic style is drawn with a face labeled normal.', $diagnostics[0]->message);
    }

    public function testWeightNoteNamesTheFaceThatIsUsedNotAMissingFamilyFace(): void
    {
        // The variant is matched before the weight: the labeled small-caps 400 face wins although a bold face exists.
        $fonts = new FontSet(self::$lato, bold: self::$latoBold, faces: [new FamilyFace(self::$latoItalic, 400, smallCaps: true)]);
        $style = new ComputedStyle(fontWeight: 700, smallCaps: true);
        $diagnostics = new Diagnostics();
        self::assertSame(self::$latoItalic, (new InlineFormatter(new ConversionOptions($fonts), $diagnostics))->face($style));
        self::assertSame(['Weight 700 text is drawn with a face labeled normal small-caps 400, across the bold threshold from the request.'], array_column($diagnostics->all(), 'message'));

        // The style is matched before the weight: the only italic face is 400 although a normal bold face exists.
        [$faces, $notes] = $this->faces('<p><b><i>both</i></b></p>', new FontSet(self::$lato, bold: self::$latoBold, italic: self::$latoItalic));
        self::assertSame(self::$latoItalic, $faces['both']);
        self::assertSame(['Weight 700 text is drawn with a face labeled italic 400, across the bold threshold from the request.'], array_column($notes, 'message'));
    }

    public function testACharacterTakenFromARelaxedFaceIsReportedLikeAWholeRun(): void
    {
        // U+211D is not in Lato: the bold italic request draws it with the upright regular default face.
        $fonts = new FontSet(self::$noto, catalog: new FontCatalog(['Lato' => new FontSet(self::$lato, self::$latoBold, self::$latoItalic, self::$latoBoldItalic)]));
        [$faces, $diagnostics] = $this->faces('<p style="font-family:Lato"><b><i>a' . "\u{211D}" . 'b</i></b></p><p style="font-family:Lato"><b><i>' . "\u{211D}" . '</i></b></p>', $fonts);
        self::assertSame(self::$latoBoldItalic, $faces['a']);
        self::assertSame(self::$noto, $faces["\u{211D}"]);
        self::assertSame(['font.style-fallback', 'font.weight-fallback'], array_map(static fn(Diagnostic $item): string => $item->code, $diagnostics), 'once per request, although two paragraphs use the fallback');
        self::assertSame('Text requested in italic style is drawn with a face labeled normal.', $diagnostics[0]->message);
        self::assertSame('Weight 700 text is drawn with a face labeled normal 400, across the bold threshold from the request.', $diagnostics[1]->message);

        // The same character taken from a face that satisfies the request needs no note.
        $complete = new FontSet(self::$noto, catalog: new FontCatalog(['Lato' => new FontSet(self::$lato), 'Noto' => new FontSet(self::$noto)]));
        [, $none] = $this->faces('<p style="font-family:Lato, Noto">a' . "\u{211D}" . 'b</p>', $complete);
        self::assertSame([], $none);
    }

    public function testEveryFaceOfTheDefaultFamilyIsALastResortForAGlyph(): void
    {
        // The bold request matches Lato Bold, which lacks U+211D; the regular face of the same default family has it.
        [$faces, $diagnostics] = $this->faces('<p><b>a' . "\u{211D}" . '</b></p>', new FontSet(self::$noto, bold: self::$latoBold));
        self::assertSame(self::$latoBold, $faces['a']);
        self::assertSame(self::$noto, $faces["\u{211D}"]);
        self::assertSame(['font.weight-fallback'], array_map(static fn(Diagnostic $item): string => $item->code, $diagnostics));

        // A listed family contributes its matched face only: its other faces are not searched.
        $fonts = new FontSet(self::$lato, catalog: new FontCatalog(['Mixed' => new FontSet(self::$noto, bold: self::$latoBold)]));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('No glyph for U+211D');
        $this->faces('<p style="font-family:Mixed"><b>a' . "\u{211D}" . '</b></p>', $fonts);
    }

    public function testLongFamilyListsAreBoundedAndKeptShortInMessages(): void
    {
        $names = implode(', ', array_map(static fn(int $index): string => '"Missing family number ' . $index . '"', range(1, 64)));
        [, $diagnostics] = $this->faces('<p style=\'font-family:' . $names . '\'>text</p>', new FontSet(self::$noto));
        self::assertSame(['font.default-family'], array_map(static fn(Diagnostic $item): string => $item->code, $diagnostics));
        self::assertLessThan(260, strlen($diagnostics[0]->message));
        self::assertStringContainsString('…', $diagnostics[0]->message);

        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('limited to 64 names');
        $this->faces('<p style=\'font-family:' . $names . ', "One too many"\'>text</p>', new FontSet(self::$noto));
    }

    public function testWeightFallbackAcrossTheBoldThresholdIsReportedOnce(): void
    {
        [$faces, $diagnostics] = $this->faces('<p><strong>one</strong> and <b>two</b></p><h1>three</h1>', new FontSet(self::$noto));
        self::assertSame(self::$noto, $faces['one']);
        self::assertSame(self::$noto, $faces['three']);
        self::assertSame(['font.weight-fallback'], array_map(static fn(Diagnostic $item): string => $item->code, $diagnostics));
    }

    public function testNotesAreBoundedPerCodeAndNeverExhaustTheDiagnosticBudget(): void
    {
        $html = '';
        foreach (range(1, 30) as $index) {
            $html .= '<p style="font-family:Missing' . $index . '">t' . $index . '</p>';
        }
        [$faces, $diagnostics] = $this->faces($html, new FontSet(self::$noto));
        self::assertCount(30, $faces);
        self::assertCount(9, $diagnostics);
        self::assertSame('Further font.default-family notes are omitted.', $diagnostics[8]->message);

        // Notes have their own allowance: a full budget of required diagnostics neither drops nor is dropped by them.
        $options = new ConversionOptions(new FontSet(self::$noto), new PageSettings(400, 600, new Edges(20, 20, 20, 20)), unsupported: UnsupportedPolicy::Report);
        $full = new Diagnostics(UnsupportedPolicy::Report, 2);
        $document = (new HtmlCompiler())->parse(new HtmlSource('<p style="position:static;top:0;font-family:Missing"><strong>text</strong></p>'), $options, $full);
        self::assertCount(2, $full->all());
        (new LayoutEngine())->layout($document, $options, $full);
        self::assertSame(['css.property', 'css.property', 'font.default-family', 'font.weight-fallback'], array_column($full->all(), 'code'));
    }

    public function testANoteNeverTakesThePlaceOfARequiredDiagnostic(): void
    {
        // 99 unsupported declarations, one note, then the hundredth required diagnostic from the automatic table.
        $html = str_repeat('<p style="position:static">x</p>', 99) . '<p style="font-family:Verdana">text</p><table><tr><td>cell</td></tr></table>';
        $options = new ConversionOptions(new FontSet(self::$noto), new PageSettings(400, 4000, new Edges(20, 20, 20, 20)), unsupported: UnsupportedPolicy::Report);
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = (new HtmlCompiler())->parse(new HtmlSource($html), $options, $diagnostics);
        (new LayoutEngine())->layout($document, $options, $diagnostics);
        $codes = array_count_values(array_column($diagnostics->all(), 'code'));
        self::assertSame(['css.property' => 99, 'font.default-family' => 1, 'layout.table-auto' => 1], $codes);

        $notes = new Diagnostics(UnsupportedPolicy::Report, 2);
        self::assertTrue($notes->inform('note', 'one'));
        self::assertTrue($notes->inform('note', 'two'));
        self::assertFalse($notes->inform('note', 'three'), 'the note allowance is bounded and silent');
        $notes->add('required', 'one');
        $notes->add('required', 'two');
        self::assertCount(4, $notes->all());
        $this->expectException(LimitExceeded::class);
        $notes->add('required', 'three');
    }

    /**
     * @return array{array<string,FontFace>,list<Diagnostic>} The face of each word and the layout diagnostics.
     */
    private function faces(string $html, FontSet $fonts, UnsupportedPolicy $policy = UnsupportedPolicy::Reject): array
    {
        $options = new ConversionOptions($fonts, new PageSettings(400, 2000, new Edges(20, 20, 20, 20)), unsupported: $policy);
        $diagnostics = new Diagnostics($policy);
        $document = (new HtmlCompiler())->parse(new HtmlSource('<style>*{margin:0;padding:0}body{font-size:10pt;line-height:12pt}</style>' . $html), $options, $diagnostics);
        self::assertSame([], $diagnostics->all());
        $pages = (new LayoutEngine())->layout($document, $options, $diagnostics);
        $faces = [];
        foreach ($pages->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof Text) {
                    foreach (preg_split('/\s+/', trim($command->text)) ?: [] as $word) {
                        $faces[$word] = $command->font;
                    }
                }
            }
        }

        return [$faces, $diagnostics->all()];
    }
}
