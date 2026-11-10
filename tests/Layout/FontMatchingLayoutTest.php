<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostic;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Style\Edges;
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
        self::assertStringContainsString('no italic face', $diagnostics[0]->message);
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

        // A full budget drops the notes instead of stopping the conversion.
        $options = new ConversionOptions(new FontSet(self::$noto), new PageSettings(400, 600, new Edges(20, 20, 20, 20)));
        $full = new Diagnostics(UnsupportedPolicy::Report, 2);
        $document = (new HtmlCompiler())->parse(new HtmlSource('<p style="position:static;top:0;font-family:Missing"><strong>text</strong></p>'), $options, $full);
        self::assertCount(2, $full->all());
        (new LayoutEngine())->layout($document, $options, $full);
        self::assertSame(['css.property', 'css.property'], array_column($full->all(), 'code'));
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
