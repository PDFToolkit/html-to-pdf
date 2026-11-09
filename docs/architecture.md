# Architecture

[English](architecture.md) · [Français](fr/architecture.md)

`Converter` creates a fresh pipeline for each document. The conversion result is
an immutable value containing bytes, page count and diagnostics. It holds no DOM
or open resource. File/network access remains the caller's responsibility.

```mermaid
flowchart LR
  A[HtmlSource and explicit resources] --> B[HTML5 parser and CSS cascade]
  B --> C[Immutable nodes and computed styles]
  C --> D[Font shaping and pagination]
  D --> E[Pages, drawing commands and semantic tree]
  E --> F[PDFToolkit PDF renderer]
  F --> G[ConversionResult]
```

| Layer | Responsibility |
|---|---|
| `Parsing`, `Css` | Bound input, parse HTML5 and CSS, apply cascade and construct typed styles. |
| `Model`, `Style` | Immutable source identities, content and computed values, independent of PDF objects. |
| `Font` | Explicit static TrueType faces, family catalog, GSUB/GPOS shaping, metrics and variant selection. |
| `Layout` | Widths, line boxes, fragmentation, page positions and semantic identities. |
| `Drawing` | Typed text, image, rectangle, border-box and static radio operations, expressed in PDF points from the top left. |
| `Rendering` | Document-local font embeddings, marked content, structure/parent trees, images and links through the PDF core. |
| `Resource` | Exact caller-provided catalog lookup and cumulative resource budgets. |

Text command coordinates refer to the baseline. Rendering converts top-left
coordinates to PDF page coordinates. Layout measures shaped advances; painting
uses the same shaping engine. Embedding collects the used repertoire once per
face and per document. Fonts and images may be reused as immutable inputs, while
PDF resource numbers and MCIDs belong to one output document.

Logical structure is distinct from paint order. A single semantic node can refer
to content on several pages. Backgrounds and borders are artifacts; text/images
carry marked-content references and links additionally carry annotation object
references. The original semantic hierarchy is not inferred from painted pixels.

Masterminds HTML5, Sabberworm CSS Parser and Symfony CssSelector handle language
syntax. PDFToolkit's stable core, Fonts, Images and OpenType modules handle PDF
objects, shaping and raster embedding.


`DeclarationSource` reads each original value using the CSS parser's source
position and a bounded UTF-8 offset index. `Declarations` validates and expands
it atomically before `Cascade` applies importance, specificity and source order;
parser serialization cannot repair an invalid numeric token into a valid color.
`SelectorPolicy` inspects parsed selector nodes and bounds their depth before
XPath translation. A rule is applied only after its entire selector list passes.

Catalog imports are document-scoped. `CatalogUri` resolves identities without
I/O; active-path tracking detects cycles without suppressing a legitimate later
import. `ParseBudget` accounts for every parsed occurrence, import and selector.
Report recovery stays within the same byte/nesting budget and keeps diagnostics.

`BorderComputer` resolves immutable `Borders` containing four `BorderSide`
values. Layout uses each side's effective width; `Drawing\BorderBox` and
`Rendering\BorderPainter` retain the full box geometry when painting page
fragments. Borders remain artifacts. The legacy uniform style inputs remain
usable, while new integrations should inspect `ComputedStyle::borderEdges()`.

Extensions should add typed values, a bounded algorithm, negative tests and an
independent observable witness before changing the coverage matrix. A renderer
option cannot substitute for a missing layout algorithm.

`FontCatalog` resolves immutable family lists only against caller-supplied named
and generic bindings. `FontDeclarations` expands the supported shorthand before
cascade; `InlineFormatter` measures and paints through the selected face.
`FloatContext`, `LineExclusions` and a shared `FloatWorkBudget` bound placement
and line exclusion. `FlowItem::paintHeight` separates visible extent from normal
flow height; floated documents are assembled as one indivisible page context.

`HtmlDoctypeProjection` recognizes the supported legacy Strict metadata without
fetching a DTD. `HtmlDocument::canvasBackground` separates the page canvas from
body decoration. An opt-in `RadioButton` drawing becomes vector operators and
logical Form/PrintField attributes, with no PDF field or action. The historical
fixture's independent browser/PDF oracles do not import the PHP layout model.

`TextValues` validates signed spacing/indentation without weakening non-negative
box lengths. `TextSpacing` retains `normal` distinctly from zero while inheriting
computed points; `TextIndent` retains signed percentages until line-container
geometry is known. Text drawing commands carry spacing through translation and
pagination. Font shaping, line measurement, rendering and link bounds share the
same adjusted advances, with optional ligatures disabled only for nonzero letter
spacing. Grapheme boundaries keep combining marks attached; unsupported
cross-run graphemes and negative total advances fail explicitly. First-line
eligibility belongs to the block flow, so later fragments do not restart an indent.
