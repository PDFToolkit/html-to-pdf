<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Rendering;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\FormControls;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Rendering\Renderer;
use PDF\HtmlToPdf\Style\Edges;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PHPUnit\Framework\TestCase;

final class StaticRadioTest extends TestCase
{
    public function testRadioSnapshotInARepeatedTableHeaderIsRepaintedAsAnArtifact(): void
    {
        $font = new FontFace(file_get_contents(dirname(__DIR__) . '/Fixtures/fonts/NotoSans-Regular.ttf'));
        $options = new ConversionOptions(new FontSet($font, bold: $font), new PageSettings(300, 200, new Edges(20, 20, 20, 20)), formControls: FormControls::StaticSnapshot);
        $rows = '';
        foreach (range(1, 30) as $index) {
            $rows .= '<tr><td>Row ' . $index . '</td></tr>';
        }
        $html = '<!doctype html><html lang="en"><head><title>Repeated header</title><style>table{table-layout:fixed;width:100%}</style></head><body><form><table><thead><tr><th><input type="radio" name="a" checked> Head</th></tr></thead><tbody>' . $rows . '</tbody></table></form></body></html>';
        $diagnostics = new Diagnostics($options->unsupported);
        $document = (new HtmlCompiler())->parse(new HtmlSource($html), $options, $diagnostics);
        $layout = (new LayoutEngine())->layout($document, $options, $diagnostics);
        self::assertGreaterThan(1, count($layout->pages));
        $tagged = 0;
        foreach ($layout->pages as $index => $page) {
            $radios = array_values(array_filter($page->commands, static fn($command): bool => $command instanceof RadioButton));
            self::assertCount(1, $radios, 'the header is painted on every page');
            self::assertTrue($radios[0]->checked);
            self::assertSame($index === 0, $radios[0]->semanticId !== null, 'only the first occurrence belongs to the structure');
            $tagged += $radios[0]->semanticId !== null ? 1 : 0;
        }
        self::assertSame(1, $tagged);
        self::assertSame([], $diagnostics->all());
        $data = (new DocumentInspector())->inspect((new DocumentReader())->read(new FileBytes((new Renderer())->render($layout))))->jsonSerialize();
        self::assertFalse($data['truncated']);
        self::assertSame([], $data['diagnostics']);
    }

    public function testOriginalWitnessPreservesBothRadioSnapshotsAndTaggedSelectableText(): void
    {
        $font = new FontFace(file_get_contents(dirname(__DIR__, 2) . '/examples/assets/fonts/Lato-Regular.ttf'));
        $options = new ConversionOptions(new FontSet($font, catalog: new FontCatalog(genericFamilies: ['sans-serif' => new FontSet($font)])), new PageSettings(margin: new Edges()), formControls: FormControls::StaticSnapshot);
        $source = new HtmlSource(file_get_contents(dirname(__DIR__, 2) . '/resources/css1/w3c/test5526c.htm'), baseUri: 'https://www.w3.org/Style/CSS/Test/CSS1/current/test5526c.htm');
        $diagnostics = new Diagnostics($options->unsupported);
        $document = (new HtmlCompiler())->parse($source, $options, $diagnostics);
        $layout = (new LayoutEngine())->layout($document, $options, $diagnostics);
        self::assertCount(1, $layout->pages);
        $radios = array_values(array_filter($layout->pages[0]->commands, static fn($command): bool => $command instanceof RadioButton));
        self::assertCount(2, $radios);
        self::assertFalse($radios[0]->checked || $radios[1]->checked);
        self::assertGreaterThan($radios[0]->y, $radios[1]->y);
        self::assertSame([], $diagnostics->all());
        $pdf = (new Renderer())->render($layout);
        self::assertStringContainsString('/PrintField', $pdf);
        self::assertStringContainsString('/Role /rb', $pdf);
        self::assertStringNotContainsString('/AcroForm', $pdf);
        self::assertStringNotContainsString('/Widget', $pdf);
        self::assertStringContainsString('/ToUnicode', $pdf);
        $data = (new DocumentInspector())->inspect((new DocumentReader())->read(new FileBytes($pdf)))->jsonSerialize();
        self::assertFalse($data['truncated']);
        self::assertSame([], $data['diagnostics']);
    }
}
