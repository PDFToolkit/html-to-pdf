# HTML to PDF

[English](README.md) · [Français](README.fr.md)

An independent, object-oriented **native PHP** HTML/CSS paginator built on the
PDFToolkit core. It produces selectable Unicode text, embedded TrueType fonts,
images and a logical PDF structure tree without a browser or JavaScript engine.

Version 0.5 extends the documented native input profile. HTML/CSS coverage remains
partial, and unsupported features are rejected by default. See
[coverage](docs/coverage.md), [architecture](docs/architecture.md) and
[examples](examples/README.md). Tagged output is not a PDF/UA or PDF/A certificate. Development follows
[CSS1 → CSS2 → CSS2.1 → modules](docs/css-levels.md), with explicit qualification gates.

Version 0.5 works through the CSS1 font properties: size keywords, `bolder` and
`lighter`, `oblique`, synthesized `small-caps`, faces labeled with any of the nine
weights, CSS font matching across the family list and per-character fallback.
No bold, italic or oblique is synthesized. Version 0.4 added signed
`word-spacing`, `letter-spacing` and first-line `text-indent`. The
[CSS1 examples](docs/css1.md) show these properties alongside the historical
box/float/clear case and independent borders. Text remains selectable and link
annotations follow its geometry. The registry records 54 partial and
8 unsupported requirements; **complete CSS1 remains unqualified**.

## Install

```sh
composer require pdftoolkit/html-to-pdf:^0.5
```

The core is installed under its canonical Composer name `pdftoolkit/pdf:^0.12.2`.
If your application directly requires `roukmoute/pdf`, replace that requirement
with `pdftoolkit/pdf:^0.12.2` and update the lock with Composer.
PHP namespaces and APIs are unchanged.

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
by the document: no bold, italic or oblique is synthesized. An absent variant is
matched to a supplied face as CSS defines and reported by an informational
diagnostic; a character missing from one family is taken from the next listed
family or the default family. Unsupported scripts and a character without a
glyph in every candidate face fail explicitly. No system font is ever used.

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

This independent implementation is licensed under MIT and is developed from
primary HTML/CSS specifications. Bundled fonts retain their separate OFL licenses;
the unchanged historical W3C fixture retains its BSD notice.
See [primary references and asset provenance](docs/references.md).
