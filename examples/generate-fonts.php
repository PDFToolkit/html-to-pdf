<?php

declare(strict_types=1);

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Style\FontSlant;

require dirname(__DIR__) . '/vendor/autoload.php';

// File access belongs to this example CLI; conversion receives explicit bytes and labels.
$root = dirname(__DIR__);
$destination = $argv[1] ?? $root . '/_build/fonts';
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create the fonts output directory.');
}
$read = static function (string $path): string {
    $bytes = file_get_contents($path);
    return $bytes === false ? throw new RuntimeException('Cannot read ' . $path) : $bytes;
};
$html = $read(__DIR__ . '/sources/fonts.html');
$bindings = json_decode($read(__DIR__ . '/sources/fonts.json'), true, 16, JSON_THROW_ON_ERROR);

/** @var array<string, FontFace> $programs One parsed program per file, shared by every label that names it. */
$programs = [];
$files = [];
$family = static function (array $description) use (&$programs, &$files, $read, $root): FontSet {
    $regular = null;
    $additional = [];
    foreach ($description['faces'] as $face) {
        $file = $face['file'];
        if (!isset($programs[$file])) {
            $bytes = $read($root . '/' . $file);
            $programs[$file] = new FontFace($bytes);
            $files[$file] = hash('sha256', $bytes);
        }
        $labeled = new FamilyFace($programs[$file], $face['weight'], FontSlant::from($face['style']), $face['smallCaps'] ?? false);
        if ($regular === null && $labeled->key() === 'normal:normal:400') {
            $regular = $labeled->face;
        } else {
            $additional[] = $labeled;
        }
    }

    return new FontSet($regular ?? throw new RuntimeException('Every family needs a normal 400 face.'), faces: $additional);
};
$default = $family($bindings['default']);
$fonts = new FontSet($default->regular, faces: array_slice($default->faces, 1), catalog: new FontCatalog(
    array_map($family, $bindings['families'] ?? []),
    array_map($family, $bindings['generic'] ?? []),
));
$result = (new Converter())->convert(new HtmlSource($html), new ConversionOptions($fonts));
file_put_contents($destination . '/fonts.pdf', $result->pdf);
file_put_contents($destination . '/manifest.json', json_encode([
    'engine' => 'native-php', 'php' => PHP_VERSION,
    'sourceSha256' => hash('sha256', $html), 'bindingsSha256' => hash('sha256', $read(__DIR__ . '/sources/fonts.json')),
    'pdfSha256' => hash('sha256', $result->pdf), 'pages' => $result->pageCount, 'diagnostics' => $result->diagnostics,
    'bindings' => $bindings, 'fontFiles' => $files,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, 'fonts: ' . $result->pageCount . ' page(s), ' . strlen($result->pdf) . " bytes\n");
