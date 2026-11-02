# Coverage of the native input profile

[English](coverage.md) · [Français](fr/coverage.md)

Version 0.3 extends the native paginator with bounded floats, explicit CSS font families and page-canvas backgrounds. **It does not yet implement complete
CSS1, CSS2 or a complete modern CSS module.** Development and qualification follow
the [CSS level sequence](css-levels.md). Existing later-level capabilities remain
explicit extensions; they do not close missing CSS1 requirements.

| Area | Implemented profile | Remaining limits |
|---|---|---|
| HTML | Explicit UTF-8 HTML5 parser, repaired tree, title/lang, headings, paragraphs, containers, inline emphasis, definition lists, images, tables and text links. Recognized HTML4 Strict doctypes remain accepted by the HTML5 pipeline. | No JavaScript, general form controls, SVG, MathML, media, ARIA remapping or arbitrary custom-element layout. |
| Cascade | Author stylesheets, catalog style/link and ordered `@import`, inline style, specificity, source order, `!important`, inheritance and global keywords. Original declarations are validated before cascading; invalid shorthands are discarded atomically in Report mode. | No user-origin sheet, layers, scope or custom properties. Reject remains strict; Report recovers selected syntax errors, not every browser error case. Escaped declaration values remain unsupported. |
| Selectors | Type/class/ID, escaped identifiers, groups, descendant/child/sibling and supported structural selectors via Symfony. Selector lists are validated atomically and their trees are bounded. | Pseudo-elements and dynamic link/interaction states remain rejected. This is not a full Selectors specification. |
| Media | Simple print/all selection, with screen rules excluded. | No media features or `@page`. Page geometry is an explicit object. |
| Fonts | Supplied regular/bold/italic/bold-italic static TrueType faces, ordered family names and explicit generic bindings, atomic supported `font` shorthand, embedded programs, GSUB/GPOS and selectable Unicode. | No system lookup, per-character fallback, small caps, size keywords, relative/full weight matching, variable fonts, WOFF, CFF, bidi or complex-script layout. |
| Text | Normal/nowrap/pre/pre-wrap, explicit line breaks, left/center/right alignment, underline, shaped line measurement, nonbreaking spaces and explicit line heights including negative half-leading. | No justification, automatic word splitting, hyphenation, letter/word spacing, text transform/indent, first-letter/first-line or inline vertical-align. |
| Colors | Validated RGB integer/percentage forms with range clipping, 3/6-digit hex, supported named colors, currentColor, transparent backgrounds/borders. | No arbitrary alpha, gradients, background images, ICC CSS colors or all modern color functions. |
| Boxes | Positive lengths, width percentages, block/inline flow, positive sibling/parent/empty-block margin collapse, padding, independent border sides, eight visible border styles plus none, backgrounds, root/body canvas propagation and explicit heights. Fixed-height visible overflow is retained in the bounded float profile; ordinary-flow overflow remains rejected. | No negative/auto/percentage margins, percentage padding/heights, decorated inline boxes, positioning, flex or grid. |
| Floats | Explicit-width left/right floats, nested contexts, line exclusion, left/right/both clearance, suppressed list markers through `display:block`. | Every document containing visible floats must fit on one page; no float fragmentation, float/table combinations, intrinsic float sizing or parent-child margin collapse in float contexts. Decorated normal-flow blocks overlapping earlier floats and floats introduced after text on the same line are rejected. |
| Static controls | Opt-in radio snapshots preserve checked/disabled state as vector appearances with logical Form/PrintField attributes. | Rejected by default; no PDF fields, submission or JavaScript. Other controls, custom radio box sizing/decorations and block/floating radios are rejected. |
| Tables | Explicit fixed columns, captions above the table, rectangular rows, cell padding/backgrounds, top/middle/bottom alignment, TH scope, repeated header artifacts and row-boundary pagination. | No intrinsic auto layout, row/column spans, collapsed borders, tfoot, styled row groups, captions below or splitting an oversized row. |
| Lists | Ordered start/value and disc/circle/square/decimal/none markers, nested lists with logical L/LI/Lbl/LBody. | No Roman/alphabetic numbering, list images, inside markers or list shorthand. |
| Pagination | A uniform page geometry, forced breaks and legacy aliases, paragraph widows/orphans, break-inside avoidance when the group fits. | An oversized avoid group fragments with a diagnostic. An indivisible line/image/row larger than a page fails. No running headers, page counters or named pages. |
| Images | Explicit PNG/JPEG bytes; proportion-preserving intrinsic sizing or explicit dimensions, PNG alpha and alternative text/decorative artifacts. | No implicit resource fetching or automatic support for arbitrary image formats. |
| PDF | PDF 1.7, embedded fonts, metadata/lang, structure tree, page-aware MCRs, URI link annotations with OBJRs, artifacts. | No PDF/A or PDF/UA conformance claim, form fields, digital signatures or internal destinations in this module. |

The [CSS1 examples](css1.md) illustrate borders and the selected historical float case. The registry
contains 49 `partial` and 13 `unsupported` entries; none is fully qualified.
Release evidence records the exact candidate and tool versions.

Definition lists retain `dl/dt/dd` in the source model and project to `Sect/P/Sect`
in the PDF structure; richer definition-list semantics are not claimed. Plain
inline wrappers can split around blocks; decorated wrappers or link/language
scopes spanning those blocks are rejected explicitly.

All geometry uses a content-box model. UA defaults belong to this package and
are not browser default-style equivalence. For reproducible templates, specify
font sizes, line heights, margins and the fixed table model explicitly.

Tests are split by parser/cascade, layout, font shaping, rendering and end-to-end
conversion. The example oracle checks output independently with qpdf, pypdf and
Poppler. Chromium/Firefox witnesses compare selected computed styles, not complete
printed-page equivalence. See the release artifacts for exact tool versions and
results; passing selected witnesses does not qualify a whole CSS level.
