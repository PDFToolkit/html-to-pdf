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
caller-supplied bytes. Image keys remain exact, case-sensitive names. Stylesheet
references are resolved to catalog keys as described below. A URL-shaped key
works only when explicitly present in the catalog: resolving a CSS reference
never performs HTTP, filesystem or `data:` loading. Repeated image uses share
their PDF embedding. `alt=""` explicitly marks an image decorative; provide
useful alternative text for meaningful figures.

Only absolute ASCII `http`, `https` and `mailto` text links are supported. They
become PDF URI annotations with structure references and are not followed during
conversion. Internal destinations, JavaScript actions and form submission are
outside this release's profile.

## Stylesheet imports and source identity

`@import "colors.css";` and `@import url("colors.css") print;` load only bytes
already registered in `ResourceCatalog::stylesheets`. Imports must precede other
rules at the top level of their sheet. Their rules precede the importing sheet's
own rules, while specificity and `!important` keep their normal precedence.
A repeated import is processed again at its new source position.

```php
$resources = new ResourceCatalog(stylesheets: [
    'https://example.invalid/styles/print.css' =>
        '@import "colors.css"; p { font-size: 12pt; }',
    'https://example.invalid/styles/colors.css' => 'p { color: #176a80; }',
]);
$source = new HtmlSource(
    '<link rel="stylesheet" href="../styles/print.css"><p>Catalog-only CSS.</p>',
    baseUri: 'https://example.invalid/documents/report.html',
);
$result = (new Converter())->convert($source,
    new ConversionOptions(fonts: $fonts, resources: $resources));
```

`HtmlSource::baseUri` is optional and applies to stylesheet references, not image
keys or PDF link targets. A linked sheet becomes the base for its own imports;
embedded `style` and `HtmlSource::stylesheet` use the supplied document base.
Without a base, relative names address the catalog root. Dot segments are
normalized, fragments are excluded from stylesheet identity and percent-encoded
path characters stay encoded. The resulting key must exist exactly in the
catalog. These identifiers do not grant access to their apparent URL scheme.

Only the supported print/all media profile activates an import. A screen-only
import is not looked up. An active cycle is rejected by default; Report records
it and continues with later rules. Misplaced imports are likewise rejected or
reported and skipped. Missing resources fail in both modes. Import counts,
depth and cumulative CSS bytes remain bounded, including repeated imports;
inactive imports still consume the import-count budget.

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

## Declaration validation, recovery and borders

The original value text is validated before it can replace a cascaded value.
In Report mode, `color: red; color: invalid` keeps red; a malformed shorthand
cannot apply only some of its sides. RGB clipping operates on the original
numeric components, rather than a parser's reserialized color. CSS escaped
identifiers are supported in selectors, but escaped declaration values such as
`color: r\65 d` remain outside the profile and produce a diagnostic or exception.

Reject remains the default for malformed or unsupported CSS. Report diagnoses
selected syntax errors and reparses the bounded source so neighboring valid
declarations and following rules can survive. This is not complete browser
syntax recovery. A selector list containing an invalid or unsupported selector
is discarded as a whole; for example `p, a:hover` does not style `p` in Report.
Dynamic states, pseudo-elements and user-origin stylesheets remain unsupported.

Stylesheet bytes must already be UTF-8. One initial `@charset "UTF-8";` is
accepted; unknown or different encodings, repeated or misplaced directives are
rejected before parsing. Report records `css.charset` and ignores that complete
stylesheet, preserving other supplied stylesheets. No encoding conversion occurs.

Borders have independent top/right/bottom/left width, style and color, with
`solid`, `dotted`, `dashed`, `double`, `groove`, `ridge`, `inset`, `outset` and
`none`. Shorthands expand before cascade, omitted components reset to their
initial values, and `inherit` uses the parent's computed sides. `none` has zero
used width; a transparent visible-style border keeps its geometric width.

This implementation chooses `thin = 1px`, `medium = 3px`, `thick = 5px`, or
0.75/2.25/3.75 PDF points. These are package choices, not universal CSS-mandated
widths. Border decorations on inline boxes remain unsupported, as do collapsed
table borders. See the [CSS1 example](css1.md) for the supported block/cell profile.

For callers inspecting computed styles, use `ComputedStyle::borderEdges()` and
the immutable `Borders`/`BorderSide` objects. The legacy `borderWidth` and
`borderColor` fields remain available as top-side projections in compiled styles;
for a transparent top side, the color projection falls back to the text color.
They cannot represent all four sides or transparency. A manually constructed
0.1 style without `borders` still describes a uniform solid border.

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
| HTML/CSS nesting and selector-tree depth | 64 |
| Text characters | 100,000 |
| CSS rule/declaration scan budget | 1,000 |
| Selector work (selector bytes × candidate elements) | 500,000 |
| CSS imports (`maxImports`) | 128 |
| Nested CSS imports (`maxImportDepth`) | 16 |
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
