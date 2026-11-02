# CSS1: independent borders and original color values

[English](css1.md) · [Français](fr/css1.md)

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

The [coverage registry](../resources/css-coverage.json) records 45 partial and
17 unsupported requirements. **CSS1 remains incomplete**, with text/font work
next in the [qualification sequence](css-levels.md). No PDF/A or PDF/UA claim is made.
