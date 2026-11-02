# Usage and policies

[English](usage.md) · [Français](fr/usage.md)

## Explicit inputs

The library accepts UTF-8 HTML and optional CSS strings. `HtmlSource::stylesheet`
is an author stylesheet. Inline `style`, HTML `style` elements and linked styles
participate in the documented cascade. An external stylesheet is a lookup in
`ResourceCatalog`, never an implicit request.

```php
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\Style\Edges;

$resources = new ResourceCatalog(
    images: ['cover.png' => new ImageAsset($pngBytes)],
    stylesheets: ['print.css' => 'p { color: #176a80; }'],
);
$options = new ConversionOptions(
    fonts: $fonts,
    page: new PageSettings(width: 595.275590551, height: 841.88976378,
        margin: new Edges(36, 36, 36, 36)),
    resources: $resources,
);
$result = (new Converter())->convert(new HtmlSource($html), $options);
```

`$fonts` is an explicit `FontSet` as in the README. `$pngBytes` and `$html` are
caller-supplied bytes. Resource keys are opaque, exact, case-sensitive names.
A URL-shaped key works only when explicitly present in the catalog. No HTTP,
filesystem, directory traversal or `data:` resolution occurs. Repeated image
uses share their PDF embedding. `alt=""` explicitly marks an image decorative;
provide useful alternative text for meaningful figures.

Only absolute ASCII `http`, `https` and `mailto` text links are supported. They
become PDF URI annotations with structure references and are not followed during
conversion. Internal destinations, JavaScript actions and form submission are
outside this release's profile.

## Pages, fonts and CSS

Page geometry is expressed in points. A4 with 36-point margins is the default.
The CSS conversion is `1px = 0.75pt`; millimetres, centimetres, inches, points and
supported font-relative values are converted before layout. Page size/margins
are configured by `PageSettings`, not `@page` in this release.

Supply static TrueType `glyf` fonts with embedding rights. Font parsing enforces
embedding restrictions and explicit `FontLimits`. Actual variants are required;
this release supports horizontal Latin, Greek and Cyrillic text plus supported
Common/Inherited characters. It does not implement Unicode bidi, script
itemization, Arabic/Indic shaping, CJK layout or vertical writing. A glyph outside
the supplied face or supported profile fails explicitly, even in Report mode.

Tables require `table-layout: fixed` in strict mode. Choose explicit first-row
column widths or equal columns. See [coverage](coverage.md) for the precise
fragmentation, whitespace and box restrictions.

## Unsupported input

`UnsupportedPolicy::Reject` is the default. `UnsupportedFeature` stops conversion
when a recognized unsupported feature is encountered. To inspect a bounded
approximation intentionally, pass `unsupported: UnsupportedPolicy::Report` and
review every `ConversionResult::diagnostics` entry (`code`, `message`, `nodeId`).
Report mode is not a fidelity promise and does not waive missing resources,
unsafe links, missing glyphs, indivisible overflow or resource limits. Errors
produce no successful partial result. JavaScript is never executed.

## Limits

Each conversion owns its budgets; previous conversions cannot exhaust or reset
the next one's counters. Defaults are explicit and configurable:

| `ConversionLimits` | Default |
|---|---:|
| HTML bytes | 1 MiB |
| Cumulative parsed CSS bytes | 256 KiB |
| HTML/semantic nodes | 10,000 |
| Nesting depth | 64 |
| Text characters | 100,000 |
| CSS rule/declaration scan budget | 1,000 |
| Selector work (selector bytes × candidate elements) | 500,000 |
| Pages | 200 |
| Drawing commands | 50,000 |
| Catalog bytes, including unused resources | 16 MiB |
| Cumulative catalog image pixels | 16,000,000 |
| Serialized PDF bytes | 32 MiB |

`ImageAsset` separately checks its byte and pixel limits before embedding.
`FontLimits` separately bounds font parsing and each shaping run. Output length
is checked after serialization; it is not a process memory limit. Applications
converting untrusted documents should also use their usual worker memory/time
limits. Raising a limit does not add support for an absent feature.

The complete Lato examples use three prepared faces and are run with
`php -d memory_limit=512M examples/generate.php _build/examples`. Parsed OpenType
tables use more memory than compressed font bytes: the measured Lato fixture
needs about 52 MiB per prepared face. Unused variants are prepared lazily.
