# Coverage of the native input profile

[English](coverage.md) · [Français](fr/coverage.md)

Version 0.1 is a first native paginator. **It does not yet implement complete
CSS1, CSS2 or a complete modern CSS module.** Development and qualification follow
the [CSS level sequence](css-levels.md). Existing later-level capabilities remain
explicit extensions; they do not close missing CSS1 requirements.

| Area | Implemented profile | Remaining limits |
|---|---|---|
| HTML | Explicit UTF-8 HTML5 parser, repaired tree, title/lang, headings, paragraphs, containers, inline emphasis, lists, images, tables and text links. | No JavaScript, forms, SVG, MathML, media, ARIA remapping or arbitrary custom-element layout. |
| Cascade | Author stylesheets, style/link catalog entries, inline style, specificity, source order, `!important`, inheritance, `initial/inherit/unset`. | No user-origin sheet, `@import`, layers, scope or custom properties. Strict validation differs from a browser's forward-compatible error recovery. |
| Selectors | Type/class/ID, groups, descendant/child/sibling and supported structural selectors via Symfony. | Escaped selectors, pseudo-elements and dynamic link/interaction states are rejected. This is not a full Selectors specification. |
| Media | Simple print/all selection, with screen rules excluded. | No media features or `@page`. Page geometry is an explicit object. |
| Fonts | Supplied regular/bold/italic/bold-italic static TrueType faces, embedded programs, GSUB ligatures and GPOS positioning, selectable Unicode. | No CSS font-family matching, font shorthand, variable fonts, WOFF, CFF, fallback chains, bidi or complex-script layout. |
| Text | Normal/nowrap/pre/pre-wrap, explicit line breaks, left/center/right alignment, underline, shaped line measurement, nonbreaking spaces. | No justification, automatic word splitting, hyphenation, letter/word spacing, text transform/indent, first-letter/first-line or inline vertical-align. |
| Colors | RGB, 3/6-digit hex, supported named colors, currentColor, transparent background. | No arbitrary alpha, gradients, background images, ICC CSS colors or all modern color functions. |
| Boxes | Positive lengths, width percentages, block/inline flow, positive sibling/parent/empty-block margin collapse, padding, uniform solid borders, backgrounds, explicit heights with overflow rejection. | No negative/auto/percentage margins, percentage padding/heights, individual border sides/styles, floats, positioning, flex or grid. |
| Tables | Explicit fixed columns, rectangular rows, cell padding/backgrounds, top/middle/bottom alignment, TH scope, repeated header artifacts and row-boundary pagination. | No intrinsic auto layout, row/column spans, collapsed borders, tfoot, styled row groups, captions below or splitting an oversized row. |
| Lists | Ordered start/value and disc/circle/square/decimal/none markers, nested lists with logical L/LI/Lbl/LBody. | No Roman/alphabetic numbering, list images, inside markers or list shorthand. |
| Pagination | A uniform page geometry, forced breaks and legacy aliases, paragraph widows/orphans, break-inside avoidance when the group fits. | An oversized avoid group fragments with a diagnostic. An indivisible line/image/row larger than a page fails. No running headers, page counters or named pages. |
| Images | Explicit PNG/JPEG bytes; proportion-preserving intrinsic sizing or explicit dimensions, PNG alpha and alternative text/decorative artifacts. | No implicit resource fetching or automatic support for arbitrary image formats. |
| PDF | PDF 1.7, embedded fonts, metadata/lang, structure tree, page-aware MCRs, URI link annotations with OBJRs, artifacts. | No PDF/A or PDF/UA conformance claim, form fields, digital signatures or internal destinations in this module. |

All geometry uses a content-box model. UA defaults belong to this package and
are not browser default-style equivalence. For reproducible templates, specify
font sizes, line heights, margins and the fixed table model explicitly.

Tests are split by parser/cascade, layout, font shaping, rendering and end-to-end
conversion. The example oracle checks output independently with qpdf, pypdf and
Poppler. Chromium/Firefox witnesses compare selected computed styles, not complete
printed-page equivalence. See the release artifacts for exact tool versions and
results; passing selected witnesses does not qualify a whole CSS level.
