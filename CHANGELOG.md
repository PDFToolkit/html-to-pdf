# Changelog

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## Unreleased

## 0.2.1 — 2026-11-02

- Use the canonical Composer package `pdftoolkit/pdf:^0.12.2` and migrated stable dependencies. Keep existing PHP namespaces, APIs and behavior.
- Document migration of application requirements in English and French; preserve previous releases and their evidence.

## 0.2.0 — 2026-11-02

See the release assets for exact-candidate PHP, PDF and browser validation
receipts; the CSS1 completion gate remains incomplete.

- Validate original declaration values before the author cascade; preserve valid
  earlier values and reject malformed shorthands atomically. Clip original RGB
  components without trusting a parser's normalized serialization.
  Accept explicit positive RGB signs without changing source offsets, and reject
  unsupported or misplaced encoding directives before they change parser state.
- Recover selected syntax errors in Report mode while keeping Reject strict;
  validate whole selector lists, support escaped selector identifiers, and bound
  selector-tree depth. Escaped declaration values remain unsupported.
- Resolve ordered `@import` only against explicitly supplied stylesheet bytes,
  with optional `HtmlSource::baseUri`, cycle diagnostics and cumulative import,
  depth and CSS-byte limits. No implicit network or filesystem access.
- Add independent border sides, eight visible styles plus none, shorthand reset
  and inheritance, transparent geometry and fragment-aware artifact painting.
  Inline border decorations and collapsed table borders remain unsupported.
- Add an original CSS1 visual example and update bilingual guides. The registry
  now records 45 partial and 17 unsupported requirements, with no full CSS1 claim.
- Preserve the legacy uniform `ComputedStyle` constructor; compiled borderWidth
  and borderColor are top-side projections. Use borderEdges() for independent sides.

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
the qualification sequence documented in `docs/css-levels.md`. PDF/A and PDF/UA
certification remain outside the validated profile.
