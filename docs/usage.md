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

ASCII `http`, `https` and `mailto` text links become PDF URI annotations with
structure references and are not followed during conversion. Relative targets
require an explicit `HtmlSource::baseUri`; their fragment stays in the external
URI. A relative target without a supported base is rejected. Internal destinations,
JavaScript actions and form submission are outside this release's profile.

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

`HtmlSource::baseUri` is optional and applies to stylesheet references and text
link targets, not image keys. A linked sheet becomes the base for its own imports;
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
embedding restrictions and explicit `FontLimits`. No bold, italic or oblique is
synthesized: a style or weight uses a face you supply, chosen by CSS font matching.
This release supports horizontal Latin, Greek and Cyrillic text plus supported
Common/Inherited characters. It does not implement Unicode bidi, script
itemization, Arabic/Indic shaping, CJK layout or vertical writing. A character
outside the supported profile, or without a glyph in every supplied family,
fails explicitly, even in Report mode.

Tables require `table-layout: fixed` in strict mode. Choose explicit first-row
column widths or equal columns. See [coverage](coverage.md) for the precise
fragmentation, whitespace and box restrictions.

## Explicit font families

CSS `font-family` lists are matched, in order, against the families registered
in a `FontCatalog`, as [font matching](#font-matching-and-character-fallback)
describes. Named families are case-insensitive; quoted names remain distinct from generic
keywords. Bind generic families deliberately rather than relying on system fonts:

```php
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;

$regular = new FontFace($regularBytes);
$sans = new FontSet($regular, bold: new FontFace($boldBytes));
$fonts = new FontSet($regular, catalog: new FontCatalog(
    families: ['Report Sans' => $sans],
    genericFamilies: ['sans-serif' => $sans],
));
// CSS: font: bold 10px/1.3 "Report Sans", sans-serif;
```

The default `FontSet` serves documents without a CSS family declaration and is
the last resort of every family list. There is no filesystem or system lookup.

### Labeled faces

A `FontSet` is one family. Its four named slots are labeled by position: `regular`
is normal 400, `bold` normal 700, `italic` italic 400 and `boldItalic` italic 700.
Any other face is added with explicit labels:

```php
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Style\FontSlant;

$family = new FontSet($regular, bold: $bold, italic: $italic, faces: [
    new FamilyFace($light, 300),
    new FamilyFace($black, 900),
    new FamilyFace($slanted, 400, FontSlant::Oblique),
    new FamilyFace($smallCapitals, 400, smallCaps: true),
]);
```

Labels are your statement about a file. They are never read from the font or
guessed from its name, and two faces of one family cannot share the same style,
variant and weight. The `regular` face is the family's normal 400 face.

### Font matching and character fallback

Text is matched as CSS1 5.2.1 and CSS 2.1 15.2 describe, in the order of the
`font-family` list:

1. A name without a binding is skipped.
2. Inside a family the style is tried first. `italic` accepts a face labeled
   italic or, failing that, oblique. `normal` and `oblique` need a face with that
   exact label; otherwise the family is skipped.
3. The variant comes next: `small-caps` prefers a face labeled as a small-caps font.
4. The weight never fails. A missing weight selects a face in the CSS 2.1 15.6
   order: for 600 and above darker faces first, for 300 and below lighter faces
   first, while 400 and 500 try each other before the lighter faces.
5. A character without a glyph in the matched face is taken from the next family
   of the list. A base character and its combining marks stay in one face whenever
   one face has them all.
6. The default family comes last and supplies the best match it has. Its other
   faces follow as a last resort for a character that no matched face can draw.

These outcomes are defined by CSS, so they do not fail. A caller who needs to
know about them reads `ConversionResult::$diagnostics`, in both policies:

| Code | Meaning |
|---|---|
| `font.default-family` | No listed family supplied a face; the default family is used. |
| `font.style-fallback` | The face used does not have the requested style: the default family has no such face, or none that has the character. |
| `font.weight-fallback` | The face used is on the other side of the bold threshold (600) from the request. |
| `font.cluster-split` | No single face has all characters of a grapheme cluster; they are drawn with different fonts. |
| `font.small-caps-fallback` | No face has the uppercase glyph of a lowercase letter; it is drawn as written. |

Notes are deduplicated, limited to eight per code and counted apart from the
diagnostics a conversion must record, so they never exhaust that budget. After
the eighth, one `Further <code> notes are omitted.` entry with the same code
closes the series. A face is reported whether it draws a whole run or one
character taken by fallback. One combination is rejected in both policies: a
nonzero `letter-spacing` on a grapheme cluster divided between fonts raises
`UnsupportedFeature`.

Each run of text in another face or size is shaped on its own and is one drawing
command: kerning and ligatures do not cross a font change. The runs of one inline
box share one link rectangle. Each styled run contributes the metrics of its
first matching face to the line box; a character taken from a fallback face does
not change it. A `font-family` list is limited to 64 names.

Small capitals and character fallback therefore multiply text commands, about two
per word for synthesized small capitals. A long text reaches `maxCommands` and the
memory limit sooner: about two to three times the memory of the same text as
written, the higher figure for long texts. Negative spacing is judged run by run.
In small capitals a space is a run of its own, so a negative `word-spacing` wider
than a space is rejected although the same text as written keeps a positive
advance. Each run also carries its own `ActualText`: a line made only of
one-letter words in synthesized small capitals keeps its spaces in content order,
but a reader that rebuilds words from glyph positions can join them.

`FontSet::face()` remains an exact lookup of a named slot and still fails when
the slot is empty. Version 0.4 raised `UnsupportedFeature` for a missing bold or
italic face and for a family list without a binding; those cases now follow the
rules above.

### Sizes, weights, styles and small capitals

`font-size` accepts the seven absolute keywords and the two relative ones, as
well as lengths and percentages. The table is fixed for a 12pt (16 CSS px)
medium:

| Keyword | `xx-small` | `x-small` | `small` | `medium` | `large` | `x-large` | `xx-large` |
|---|---|---|---|---|---|---|---|
| Points | 6.75 | 7.5 | 9.75 | 12 | 13.5 | 18 | 24 |

`larger` multiplies the parent's computed size by 1.2 and `smaller` divides it by
1.2. This is what both browser witnesses compute and what CSS Fonts 4 allows. The
CSS1 example in which `larger` from `medium` gives `large` is therefore not
reproduced: the result is 14.4pt, not 13.5pt. A zero size, a size above 1000pt and the `ex` unit are
not supported.

`font-weight` accepts `normal`, `bold`, `bolder`, `lighter` and `100` to `900`.
The relative keywords use the chart of CSS 2.1 15.6, which does not depend on
the fonts: from 400, `bolder` gives 700 and `lighter` 100. `strong` and `b` are
`bolder`. `font-style` accepts `normal`, `italic` and `oblique`;
`ComputedStyle::fontSlant()` returns the three values, while the boolean `italic`
remains true for italic only.

`font-variant: small-caps` draws lowercase letters with the uppercase glyphs of
the matched face at 0.8 of the font size, unless that face is labeled as a
small-caps font. Capitals, digits, spaces and punctuation are unchanged. The
uppercase mapping is the unconditional Unicode mapping of the PHP runtime
(`ß` becomes `SS`), without language-specific rules, and the extracted text stays
as written. The OpenType `smcp` feature is not used.

The `font` shorthand accepts every value above, expands atomically and resets
omitted components, including `line-height`. System-font keywords belong to
CSS 2.1 and are rejected.

## Text spacing and first-line indentation

```css
p { word-spacing: .2em; letter-spacing: 1px; text-indent: 10%; }
p.compact { word-spacing: -1px; letter-spacing: -.02em; text-indent: -12px; }
```

`word-spacing` and `letter-spacing` accept `normal`, zero and signed lengths in
`px`, `pt`, `pc`, `mm`, `cm`, `in` or `em`; the existing `rem` extension also works.
Percentages are rejected for spacing. `text-indent` accepts the same lengths and
signed percentages, with zero as its initial value. `ex` remains unsupported.
Invalid values are rejected before cascade, so a later invalid value cannot erase
an earlier valid declaration in Report mode.

All three properties inherit. Relative lengths are computed using the element's
font size and then inherited as absolute points; increasing a child's font size
does not recompute its inherited `em` value. Percentage indentation remains a
percentage until layout and uses the content width of the block containing the
lines. It is independent of a float's temporary line exclusion. Only the first
eligible formatted line is indented: `br`, a new page and later anonymous inline
fragments after an in-flow block do not restart the parent's indent. Descendant
blocks still apply their own inherited or specified first-line indent.

Word spacing applies to spaces U+0020 and nonbreaking spaces U+00A0 remaining
after whitespace processing. NBSP remains unbreakable. Nonzero letter spacing
adds an advance after each grapheme, including a final trailing advance, matching
the selected Chromium/Firefox witnesses. It leaves combining marks attached and
disables optional ligatures. `normal` and zero preserve the original shaping.
These are bounded horizontal Latin/Greek/Cyrillic text capabilities, not a claim
of complete CSS Text support. A negative total run advance is rejected. A nonzero
letter spacing on a grapheme split between inline spans is also rejected, since shaping across those
run boundaries is not implemented. An indent that leaves no positive line width
fails explicitly. Preserved tabs with nonzero `letter-spacing` or `word-spacing`
raise `UnsupportedFeature`: the existing normal-spacing tab profile is retained,
without a claim of exact CSS tab-stop placement. The existing shaping, command
and output budgets still apply.

The same shaped advances determine wrapping, PDF text, inline backgrounds,
underlines and link bounds. `ComputedStyle::textSpacing` exposes immutable
`TextSpacing` values in points (`null` means `normal`);
`ComputedStyle::textIndent` exposes a signed `TextIndent` with its percentage flag.
The [text example and independent checks](css1.md#text-spacing-and-first-line-indentation)
show the implemented behavior. Justification, text transformation and additional
decorations remain unsupported.

## Bounded floats and static controls

`float:left/right` requires an explicit width, including supported percentages.
Nested floats establish their own context; inline text uses the remaining line
space, and `clear:left/right/both` moves following content below the matching
floats. **The entire document containing visible floats must fit on one page.**
There is no float fragmentation, intrinsic float sizing or float/table combination.
Parent-child margin collapse within float contexts is rejected; explicit border
or padding can separate those margins. Fixed-height overflow stays visible in
this profile, while the total painted extent must still fit the page. A normal-flow
block with a background or border may not overlap an earlier float. A float
introduced after text on the same inline line is also rejected; these paint-order
and line-reflow contexts are not implemented.

Root `html` backgrounds paint the page canvas. When that background is transparent,
the body background is propagated to the canvas instead of painted twice. Body
box borders and margins retain their own geometry. `dl/dt/dd` remain distinct
HTML source nodes and project to `Sect/P/Sect` in PDF; this is not a richer
PDF definition-list model. Plain inline wrappers may split around block children;
decorations or link/language scopes spanning those blocks are rejected.

Form elements and controls are rejected by default. An explicit static snapshot
permits radio controls, preserving checked/disabled state as a vector appearance:

```php
use PDF\HtmlToPdf\FormControls;

$options = new ConversionOptions(
    fonts: $fonts,
    formControls: FormControls::StaticSnapshot,
);
```

The output contains logical Form/PrintField attributes, but no interactive PDF
field, submission action or JavaScript. Other control types, custom radio box
sizes/decorations and block/floating radios are unsupported. Report mode records
a diagnostic and omits unsupported controls; strict mode rejects them.
See the [historical CSS1 example](css1.md) for the complete explicit setup.

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
