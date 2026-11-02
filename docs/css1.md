# CSS1: native float example and independent borders

[English](css1.md) · [Français](fr/css1.md)

## Historical box / float / clear example

Version 0.3 renders the unchanged
[historical source](https://www.w3.org/Style/CSS/Test/CSS1/current/test5526c.htm)
with the native PHP engine. It combines left/right and nested floats, percentage
widths, fixed heights, asymmetric borders, list-marker suppression, clearance,
root backgrounds and a font shorthand. The two radios are explicit static snapshots.

![Native PDF rendering of the historical box, float and clearance case](../examples/previews/test5526c.png)

[Unmodified bundled HTML](../resources/css1/w3c/test5526c.htm) ·
[Generator](../examples/generate-w3c.php) ·
[Source hashes and provenance](../resources/css1/w3c/provenance.json) ·
[BSD fixture notice](../resources/css1/w3c/NOTICE)

The generator supplies Lato as the explicit `sans-serif` binding, uses A4 with
zero additional page margins, and keeps the HTML's own margins. It provides the
source URL only as a base for annotation links; no URL is fetched. The source's
Verdana family is unavailable in this catalog, so its declared generic fallback
selects Lato. Font rasterization and exact line wrapping can differ from browsers.

```sh
php -d memory_limit=512M examples/generate-w3c.php _build/w3c
python tools/validate_w3c_css1.py --pdf _build/w3c/test5526c.pdf \
  --manifest _build/w3c/manifest.json --output _build/w3c/validation
npm ci --prefix tools
npm exec --prefix tools -- playwright install chromium firefox
node tools/validate_w3c_css1.cjs _build/w3c/browsers
python tools/test_w3c_css1_oracle.py
node tools/test_w3c_css1_oracle.cjs
```

Python dependencies are pinned in `tools/validation-requirements.txt`; qpdf and
Poppler are also required. The browser oracle checks 50 box coordinates and 13
computed properties in each engine from identical source bytes, at 96 CSS px per
inch. The PDF oracle checks source/PDF hashes, one exact A4 page, qpdf syntax,
selectable source text through explicit CMaps and Poppler, clearance/text bounds,
and independent color masks at 96 dpi. Edge antialiasing has a 1.25 px exclusion;
color channels tolerate 4/255, with at most 0.2% mismatches per color. Font/widget
areas and the font-dependent footer band are excluded from that raster mask;
text content and paragraph clearance have separate checks.

This selected historical case does **not** qualify the whole test suite or CSS1.
Floats require explicit widths and a one-page document; float pagination,
float/table combinations and parent-child margin collapse in float contexts remain
unsupported. Decorated normal-flow blocks overlapping earlier floats and floats
introduced after text on the same line are also rejected. See
[coverage](coverage.md) and [usage](usage.md) for those boundaries.

## Independent border sides

Version 0.2 adds four independent border sides and eight visible border styles.
This original example presents `solid`, `dotted`, `dashed`, `double`, `groove`,
`ridge`, `inset` and `outset`, followed by a box with a different style, width and
color on each side. Text remains selectable; borders are PDF artifacts.

![CSS1 border styles, independent sides and RGB clipping](../examples/previews/css1.png)

[HTML source](../examples/sources/css1.html) · [Generator](../examples/generate.php) ·
[PDF download for v0.2.0](https://github.com/PDFToolkit/html-to-pdf/releases/download/v0.2.0/css1.pdf)

```css
.sides {
    border-top: 4pt solid #26788b;
    border-right: 8pt dashed #3b7557;
    border-bottom: 6pt double #ba5e24;
    border-left: 12pt dotted #78508e;
}
.rgb {
    color: rgb(-20,128,260);
    border: medium solid;
}
```

The last box clips RGB components to `rgb(0,128,255)`. Its omitted border color
uses `currentColor`. This package chooses `medium = 3px = 2.25pt`; `thin` and
`thick` use 1px and 5px. These numeric widths are implementation choices. `none`
uses no geometric border width; a transparent border with a visible style keeps
its width. Inline border decoration and collapsed table borders remain unsupported.

The release also validates original declaration values before cascade. In Report
mode an invalid later value cannot erase an earlier valid value, and an invalid
shorthand cannot partially alter its sides. Ordered, bounded imports from an
explicit catalog and escaped selector identifiers are covered in the
[usage guide](usage.md). This visual example concentrates on the border/color
subset; it is not evidence for every parser or import case.

To regenerate all examples and inspect their output:

```sh
php -d memory_limit=512M examples/generate.php _build/examples
python tools/validate_pdf.py --directory _build/examples
```

The release evidence includes independent PDF checks, every page render and
Chromium/Firefox witnesses for selected computed styles and box geometry. It
identifies the exact candidate and tool versions. A preview alone does not prove
browser/PDF equivalence or accessibility certification.

The [coverage registry](../resources/css-coverage.json) records 49 partial and
13 unsupported requirements. **CSS1 remains incomplete**, with text/font work
next in the [qualification sequence](css-levels.md). No PDF/A or PDF/UA claim is made.
