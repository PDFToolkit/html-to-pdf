# Primary references and provenance

[English](references.md) · [Français](fr/references.md)

Reviewed on 2026-11-02. This implementation is independent of iText. No iText
source, translated implementation, test fixture or marketing illustration is
included. The screenshots supplied during planning identify desired behavior;
our examples use original illustrations and separately licensed fonts.

| Reference | Use and scope |
|---|---|
| [PDF Association product listing](https://pdfa.org/product/pdfhtml/) | Identifies pdfHTML as an iText product; not an HTML-to-PDF specification. |
| [iText product](https://itextpdf.com/products/convert-html-css-to-pdf-pdfhtml) and [overview](https://itextpdf.com/blog/itext-news/pdfhtml-convert-html-rich-smart-pdf-documents) | Functional inspiration: static HTML/CSS, layout, images, tables and semantic PDF. Not a PDFToolkit conformance claim. |
| [iText pdfHTML 6.3.4 license](https://github.com/itext/itext-pdfhtml-java/blob/25569aa8930386754f142dea3323c38e667b3c38/LICENSE.md) | AGPL-3.0-or-later or commercial; no code is copied into this MIT implementation. |
| [WHATWG HTML parsing](https://html.spec.whatwg.org/multipage/parsing.html) | HTML syntax and parsing; implemented through the explicit Masterminds HTML5 dependency. |
| [CSS 2](https://www.w3.org/TR/CSS2/) | Core boxes, text and table terminology; only the documented profile is implemented. |
| [CSS1](https://www.w3.org/TR/2008/REC-CSS1-20080411/) | Historical property/behavior inventory and RGB range handling; shared definitions follow the corrected specifications. |
| [CSS syntax errors](https://www.w3.org/TR/CSS2/syndata.html#parsing-errors) | Declaration-local rejection and bounded recovery; complete browser recovery is not claimed. |
| [CSS imports and cascade](https://www.w3.org/TR/CSS2/cascade.html#at-import) | Import source order, relative sheet identity, specificity and importance. Fetching is replaced by an explicit catalog. |
| [CSS border properties](https://www.w3.org/TR/CSS2/box.html#border-properties) | Independent sides and shorthand semantics; the package documents its own keyword widths and supported contexts. |
| [CSS Cascade 5](https://www.w3.org/TR/css-cascade-5/) | Cascade/inheritance concepts; no claim for all levels, layers or scopes. |
| [CSS Values 4](https://www.w3.org/TR/css-values-4/#absolute-lengths) | Absolute conversion: 1in = 96px = 72pt. |
| [CSS Fragmentation 3](https://www.w3.org/TR/css-break-3/) | Page-break and widow/orphan concepts within the supported profile. |
| [Tagged PDF FAQ](https://pdfa.org/resource/tagged-pdf-q-a/) | Logical structure and accessibility context; tagging alone is not certification. |
| [qpdf checking](https://qpdf.readthedocs.io/en/stable/cli.html#option-check) | Independent syntax/reference checks, not a full semantic or accessibility oracle. |

The PDF Association listing and iText feature descriptions are not standards.
The HTML/CSS standards are broader than this package. The coverage matrix and
tests define what this release actually implements.

The generated fixture illustrations are original MIT assets. The example Lato
fonts retain the OFL-1.1 notice under `examples/assets/fonts/LICENCE.txt`; the
Noto Sans test face retains its OFL notice under `tests/Fixtures/fonts/OFL.txt`.
Published PDFToolkit test image provenance is recorded alongside the fixtures.
