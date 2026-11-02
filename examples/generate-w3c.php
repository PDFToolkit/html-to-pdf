<?php

declare(strict_types=1);

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\FormControls;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Style\Edges;

require dirname(__DIR__) . '/vendor/autoload.php';

// File access belongs to this example CLI; the converter receives explicit bytes only.
$destination = $argv[1] ?? dirname(__DIR__) . '/_build/w3c';
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create the witness output directory.');
}
$read = static function (string $path): string {
    return file_get_contents($path) ?: throw new RuntimeException('Cannot read ' . $path);
};
$html = $read(dirname(__DIR__) . '/resources/css1/w3c/test5526c.htm');
$bytes = $read(__DIR__ . '/assets/fonts/Lato-Regular.ttf');
$regular = new FontFace($bytes);
$sans = new FontSet($regular);
$fonts = new FontSet($regular, catalog: new FontCatalog(genericFamilies: ['sans-serif' => $sans]));
$options = new ConversionOptions($fonts, new PageSettings(margin: new Edges()), formControls: FormControls::StaticSnapshot);
$result = (new Converter())->convert(new HtmlSource($html, baseUri: 'https://www.w3.org/Style/CSS/Test/CSS1/current/test5526c.htm'), $options);
file_put_contents($destination . '/test5526c.pdf', $result->pdf);
file_put_contents($destination . '/manifest.json', json_encode([
    'engine' => 'native-php', 'php' => PHP_VERSION, 'sourceSha256' => hash('sha256', $html),
    'pdfSha256' => hash('sha256', $result->pdf), 'pages' => $result->pageCount,
    'diagnostics' => $result->diagnostics, 'fonts' => [['family' => 'Lato (explicit sans-serif binding)', 'sha256' => hash('sha256', $bytes)]],
    'formControls' => 'static-snapshot',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, 'test5526c: ' . $result->pageCount . ' page(s), ' . strlen($result->pdf) . " bytes\n");
