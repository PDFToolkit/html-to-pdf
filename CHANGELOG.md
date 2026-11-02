# Changelog

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## 0.1.0 — 2026-11-02

First independent native PHP release of `pdftoolkit/html-to-pdf`.

- Separate HTML5 parsing, CSS cascade, typed styles, font preparation, layout,
  drawing and PDF serialization, with document-scoped budgets and state.
- Selectable Unicode, supplied TrueType variants, cached OpenType preparation,
  PNG/JPEG resources, logical PDF tags and associated URI links.
- Block/inline text, lists, positive margin collapse, fixed tables with repeated
  header artifacts, explicit page breaks and paragraph widow/orphan rules.
- Reproducible invoice, illustrated catalogue and four-page inventory, with
  independent PDF/Unicode/structure/raster validation and browser style witnesses.
- English/French guides, precise unsupported-feature diagnostics and a CSS1-first
  progression registry covering all 53 CSS1 properties and cross-cutting behaviors.

This release is an experimental input profile, **not complete CSS1 or CSS2**.
CSS2 historical coverage, CSS2.1 and individually versioned modern modules follow
the qualification sequence documented in `docs/css-levels.md`. No PDF/A, PDF/UA
or full iText feature-equivalence claim is made.
