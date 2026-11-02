# HTML to PDF

[English](README.md) · [Français](README.fr.md)

An independent, object-oriented **native PHP** HTML/CSS paginator built on the
PDFToolkit core. It produces selectable Unicode text, embedded TrueType fonts,
images and a logical PDF structure tree without a browser or JavaScript engine.

This first release implements a documented input profile, not all HTML/CSS or
all iText pdfHTML features. Unsupported features are rejected by default. See
[coverage](docs/coverage.md), [architecture](docs/architecture.md) and
[examples](examples/README.md). Tagged output is not a PDF/UA or PDF/A certificate. Development follows
[CSS1 → CSS2 → CSS2.1 → modules](docs/css-levels.md), with explicit qualification gates.

## Install

```sh
composer require pdftoolkit/html-to-pdf:^0.1
```

PHP 8.3+ on a 64-bit runtime is required, as are DOM and mbstring. `php-64bit`
is a Composer platform check, not an extension to install separately. Production
dependencies are stable Composer packages; the PDFToolkit core does not depend
on this optional module.

## Convert

```php
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;

$fonts = new FontSet(
    regular: new FontFace(file_get_contents('fonts/Lato-Regular.ttf')),
    bold: new FontFace(file_get_contents('fonts/Lato-Bold.ttf')),
);
$result = (new Converter())->convert(
    new HtmlSource('<!doctype html><html lang="en"><title>Example</title>'
        .'<body><h1>Hello</h1><p>Selectable Unicode: café.</p></body></html>'),
    new ConversionOptions(fonts: $fonts),
);
file_put_contents('example.pdf', $result->pdf);
```

File reads and writes in this example belong to the calling application. The
converter accepts bytes, never fetches URLs, discovers system fonts or writes
files implicitly. Supply the actual regular/bold/italic/bold-italic variants used
by the document. Missing glyphs, unsupported scripts and absent variants fail
explicitly. No synthetic bold or silent fallback font is used.

[Usage and resource policies](docs/usage.md) explains page geometry, explicit
image/CSS catalogs, diagnostics and limits. Run the included examples with
`php -d memory_limit=512M examples/generate.php _build/examples` after `composer install`.

## Development

```sh
composer install
composer check
php -d memory_limit=512M examples/generate.php _build/examples
python tools/validate_pdf.py --directory _build/examples
```

The independent oracle requires the packages in `tools/validation-requirements.txt`,
qpdf and Poppler. CI validates PHP 8.3, 8.4 and 8.5 and retains generated PDF,
page renders and validation reports. See [releasing](RELEASING.md).

## Origins and license

MIT for this implementation. Bundled fonts retain their separate OFL licenses.
iText pdfHTML is a product listed by the PDF Association; it is a feature
reference, not a specification or code dependency. Its AGPL/commercial code
has not been copied or translated. See [primary references](docs/references.md).
