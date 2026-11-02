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
