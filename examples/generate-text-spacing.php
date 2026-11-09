<?php

declare(strict_types=1);

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;

require dirname(__DIR__) . '/vendor/autoload.php';

// File access belongs to this example CLI; conversion receives explicit bytes.
$destination = $argv[1] ?? dirname(__DIR__) . '/_build/text-spacing';
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create the text-spacing output directory.');
}
$read = static function (string $path): string {
    $bytes = file_get_contents($path);
    return $bytes === false ? throw new RuntimeException('Cannot read ' . $path) : $bytes;
};
$html = $read(__DIR__ . '/sources/text-spacing.html');
$font = $read(dirname(__DIR__) . '/tests/Fixtures/fonts/NotoSans-Regular.ttf');
$regular = new FontFace($font);
$fonts = new FontSet($regular, catalog: new FontCatalog(genericFamilies: ['sans-serif' => new FontSet($regular)]));
$result = (new Converter())->convert(new HtmlSource($html), new ConversionOptions($fonts));
file_put_contents($destination . '/text-spacing.pdf', $result->pdf);
file_put_contents($destination . '/manifest.json', json_encode([
    'engine' => 'native-php', 'php' => PHP_VERSION,
    'sourceSha256' => hash('sha256', $html), 'pdfSha256' => hash('sha256', $result->pdf),
    'pages' => $result->pageCount, 'diagnostics' => $result->diagnostics,
    'fonts' => [['family' => 'Noto Sans (explicit fallback)', 'sha256' => hash('sha256', $font)]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, 'text-spacing: ' . $result->pageCount . ' page(s), ' . strlen($result->pdf) . " bytes\n");
