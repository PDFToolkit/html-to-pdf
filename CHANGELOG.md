# Changelog

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## 0.5.0 — 2026-11-11

- Complete the value grammar of the CSS1 font properties. `font-size` accepts the
  seven absolute keywords, on a fixed table for a 12pt medium, and `larger` and
  `smaller`, which scale the parent's computed size by 1.2 as both browser
  witnesses do. `font-weight` accepts `bolder` and `lighter` with the
  font-independent chart of CSS 2.1 15.6; `strong` and `b` are `bolder`.
  `font-style` accepts `oblique` and `font-variant` accepts `small-caps`. The
  `font` shorthand accepts all of them. An undeclared size, weight, style or
  variant inherits the computed value itself.
- Match fonts as CSS1 5.2.1 and CSS 2.1 15.2 describe. A `FontSet` labels its
  four slots by position and accepts additional `FamilyFace` entries with an
  explicit weight from 100 to 900, a normal, italic or oblique style and a
  small-caps variant. Italic accepts an oblique face, other styles match exactly,
  a family that is unbound or lacks the style is skipped, missing weights follow
  the CSS 2.1 15.6 order and never fail, and the default family supplies its best
  match. A character without a glyph is taken from the next family, by grapheme
  cluster; list markers fall back the same way.
- **Behavior change:** a missing bold or italic face and a family list without
  any binding no longer raise `UnsupportedFeature`. CSS defines these cases, so
  they now produce the informational diagnostics `font.weight-fallback`,
  `font.style-fallback` and `font.default-family` in both policies, joined by
  `font.cluster-split` and `font.small-caps-fallback`. Notes are deduplicated,
  limited per code and never exhaust the diagnostic budget. `FontSet::face()`
  keeps its exact slot lookup. A character absent from every supplied face is
  still rejected.
- Synthesize small capitals from the uppercase glyphs of the matched face at 0.8
  of the font size, unless the caller labels a small-caps font. The source text,
  spacing, `ActualText` and the PDF Unicode map stay as written; `ß` draws `SS`.
  The OpenType `smcp` feature is not used.
- `ComputedStyle` gains `fontSlant()` and the `slant` and `smallCaps` fields;
  `Drawing\Text` gains `capitals`, `at()` and `detached()`. Each run of text in
  another face or size is one `Text` command; adjoining runs of one link share a
  single annotation rectangle. A family list is limited to 64 names.
- Fix repeated table headers: their artifact copy lost letter and word spacing,
  and a static radio snapshot in such a header raised a `LogicException`.
- Add an original font example with caller-labeled bindings, native PDF checks of
  embedded programs, faces, sizes, glyphs and advances, independent
  Chromium/Firefox witnesses and negative oracle tests. Seven deviations, six of
  them from the browser witnesses, are asserted by the oracles and listed in
  `docs/css1.md` instead of hidden.
- Zero font sizes, the `ex` unit, a missing-character symbol and language-specific
  casing remain unsupported. The registry now records 54 partial and 8
  unsupported requirements; CSS1, PDF/A and PDF/UA remain unqualified.

## 0.4.0 — 2026-11-09

- Add inherited `word-spacing` and `letter-spacing` with normal, zero and signed
  lengths, plus signed/percentage `text-indent` on the first eligible line.
  Preserve computed relative lengths through inheritance and reject invalid
  declarations before cascade.
- Share adjusted shaped advances between wrapping, PDF text, inline decoration
  and link bounds. Preserve Unicode and combining marks; disable optional
  ligatures only for nonzero letter spacing, leaving normal/zero shaping intact.
  Keep unsupported negative advances and cross-span graphemes explicit.
- Preserve first-line eligibility through BR, pagination and anonymous fragments
  around in-flow blocks. Resolve percentage indentation against the content width
  of the block containing the lines, independently of temporary float exclusions.
- Add an original text example, native PDF/Unicode/geometry checks, independent
  Chromium/Firefox witnesses and negative oracle tests. Document the trailing
  grapheme advance convention and the limits of browser-pagination comparisons.
- Update English/French guides and the CSS1 registry: 52 partial and 10 unsupported
  requirements. CSS1, complete text/font behavior, PDF/A and PDF/UA remain unqualified.

## 0.3.1 - 2026-11-09

- Update the locked development dependency `symfony/filesystem` to 7.4.23, addressing [GHSA-6prc-h956-hxjq](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq) in the code-style toolchain.
- Preserve production code, public APIs, PHP requirements and every other locked package version and source reference.

## 0.3.0 — 2026-11-02

- Add explicit-width left/right floats, nested exclusion contexts and clearance,
  with cumulative work limits and explicit rejection of unsupported fragmentation,
  table combinations and parent-child margin collapse. A document with visible
  floats must fit on one page; fixed-height visible overflow retains its paint extent.
- Add ordered CSS family lists, caller-supplied named/generic font bindings and
  atomic supported `font` shorthand expansion. Keep missing variants/glyphs explicit;
  no system lookup or per-character fallback. Preserve specified line heights.
- Accept recognized legacy Strict doctype metadata, definition-list source nodes,
  root/body canvas backgrounds and relative annotation links with an explicit base.
  Reject inline/block splitting that would discard a link or language scope.
- Add opt-in static radio snapshots with checked/disabled state and logical
  Form/PrintField attributes; no interactive PDF fields, submission or JavaScript.
  Unsupported control styles/types remain explicit diagnostics or errors.
- Preserve the historical box/float/clear HTML and reference GIF unchanged with
  source hashes and BSD notices. Add a native generator, browser geometry witnesses,
  independent PDF/text/color-mask checks and negative oracle controls.
- Update English/French documentation and the registry: 49 partial, 13 unsupported
  requirements. No complete CSS1, PDF/A or PDF/UA qualification is claimed.

## 0.2.2 — 2026-11-02

- Describe the native converter through primary HTML/CSS specifications, its implemented coverage and validation evidence. Keep the English/French guides and contributor policy consistent.
- Clarify independent MIT licensing and original example assets while retaining explicit CSS coverage and PDF/A/PDF/UA qualification limits.
- Documentation-only correction; conversion behavior, dependencies and public APIs are unchanged.

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
