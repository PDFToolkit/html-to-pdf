<?php

declare(strict_types=1);

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Resource\ResourceCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

// This CLI explicitly owns file access. The library receives only immutable bytes.
$destination = $argv[1] ?? dirname(__DIR__) . '/_build/examples';
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create the example output directory.');
}
$read = static function (string $path): string {
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }

    return $bytes;
};
$fonts = new FontSet(
    regular: new FontFace($read(__DIR__ . '/assets/fonts/Lato-Regular.ttf')),
    bold: new FontFace($read(__DIR__ . '/assets/fonts/Lato-Bold.ttf')),
    italic: new FontFace($read(__DIR__ . '/assets/fonts/Lato-Italic.ttf')),
);
$images = [];
foreach (['notebook', 'lamp', 'mug'] as $name) {
    $images[$name . '.png'] = new ImageAsset($read(__DIR__ . '/assets/images/' . $name . '.png'));
}
$options = new ConversionOptions(fonts: $fonts, resources: new ResourceCatalog(images: $images));
$converter = new Converter();
$manifest = ['php' => PHP_VERSION, 'engine' => 'native-php', 'documents' => []];
foreach (['invoice', 'catalogue', 'multipage', 'css1'] as $name) {
    $started = hrtime(true);
    $html = $read(__DIR__ . '/sources/' . $name . '.html');
    $result = $converter->convert(new HtmlSource($html), $options);
    file_put_contents($destination . '/' . $name . '.pdf', $result->pdf);
    $manifest['documents'][$name] = [
        'pages' => $result->pageCount,
        'elapsedSeconds' => (hrtime(true) - $started) / 1e9,
        'peakMemoryBytes' => memory_get_peak_usage(true),
        'sourceSha256' => hash('sha256', $html),
        'pdfSha256' => hash('sha256', $result->pdf),
        'bytes' => strlen($result->pdf),
        'diagnostics' => $result->diagnostics,
    ];
    fwrite(STDOUT, $name . ': ' . $result->pageCount . ' page(s), ' . strlen($result->pdf) . " bytes\n");
}
file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
