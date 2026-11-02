# CSS levels and qualification

[Français](fr/css-levels.md)

Version 0.3 is an **incomplete native HTML/CSS-to-PDF foundation**. It does not claim complete CSS1, CSS2, CSS 2.1, or “CSS3” support. Accepting a property name is not evidence that all its values, applicable elements, inherited states, layout interactions and error rules work.

The work proceeds in this order:

1. Inventory and qualify CSS1, including its properties, selectors, cascade, inheritance, formatting, font matching, units, syntax recovery, imports and canvas behavior.
2. Review the historical CSS2 additions and their scope once the CSS1 stage is qualified.
3. Complete the CSS 2.1 corrections and revised definitions, with regression checks for the earlier stages.
4. Select later CSS modules by their own version and applicable requirements. A module at level 3 or 4 is not a claim to implement all of CSS.

This is an implementation and review order, not a request to reproduce obsolete errors. The historical [CSS1 recommendation](https://www.w3.org/TR/2008/REC-CSS1-20080411/) and [CSS2 recommendation](https://www.w3.org/TR/2008/REC-CSS2-20080411/) direct implementers to the corrected shared definitions in CSS 2.1. The [CSS 2.1 recommendation](https://www.w3.org/TR/2011/REC-CSS2-20110607/) replaces CSS2, while identifying changed and removed features. The [CSS Snapshot 2025](https://www.w3.org/TR/2025/NOTE-css-2025-20250918/) groups the independently developed later specifications; it does not replace their individual requirements.

The machine-readable [coverage registry](../resources/css-coverage.json) currently inventories **53 CSS1 property names and nine behavior groups**. Its 62 entries contain 49 `partial` and 13 `unsupported` states; none is fully qualified. The property-name inventory is complete. The value/context qualification is not. CSS2, CSS 2.1 and the later module inventories remain incomplete, and every stage has `claimedComplete: false`.

An entry has a stable identifier, a normative reference, a scope note and real test paths. Property entries also include one witness value and an HTML context. `partial` means that a documented subset exists; `unsupported` means that the recorded feature is absent. A future `implemented` entry still needs independent qualification. `validated` is reserved for a qualified requirement, including its values and contexts. Updating a label cannot qualify a level by itself.

The [parameterized property witnesses](../tests/Css/Css1RegistryTest.php) parse each recorded sample using an explicitly supplied Noto font set. Partial samples must produce the expected computed field without a diagnostic. Unsupported samples must throw `UnsupportedFeature` in the default policy. The `vertical-align` witness uses a valid table-cell context: this extension does **not** establish CSS1 inline-alignment support. These tests do not render every property, enumerate every legal value, or establish conformance. The existing cascade, parsing, layout, font, PDF and browser tests provide additional, separately scoped evidence.

Validate the registry and referenced files with:

```sh
python3 tools/check_css_coverage.py --report _build/css-coverage.json
python3 tools/test_css_coverage.py
```

A consistent but incomplete registry returns exit code 0 with `status: "pass"`, `incomplete: true`, `complete: false` and `witnessesAreFullQualification: false`. This says that its declarations are coherent. The checker does not run PHP tests, render PDFs or substitute for qualification receipts.

A release that intends to claim a complete stage must use the additional gate:

```sh
python3 tools/check_css_coverage.py --require-stage css1 --report _build/css1-gate.json
```

For version 0.3, **exit code 1 and `status: "incomplete"` are the expected result**. Normal builds may publish the honestly described partial foundation; they must not treat that publication as CSS1 completion. The stage gate is mandatory when making that completion claim, not an optional way to suppress a failed conformance check.

The checker rejects duplicate identities, missing or replaced CSS1 properties, unknown states, missing test files, paths outside the repository and premature completion claims. Claiming completion requires a complete, nonempty inventory, all requirements `validated`, a hash-matched full-stage receipt with complete value/context coverage, and completion of every preceding stage. A parser-witness receipt is explicitly insufficient. The [negative gate tests](../tools/test_css_coverage.py) exercise those distinctions, including the real nonzero CLI exit for incomplete CSS1.

Future qualification must expand each property into its values and contexts, include valid and invalid inputs, inheritance and shorthand interactions, compare independent layout/rendering evidence, and retain exact-version receipts. For example, supporting `margin-top: 6pt` with positive margin collapsing does not establish negative, percentage or `auto` margins; a successful root/body background witness does not qualify every canvas context; explicit family binding does not implement per-character fallback or the complete font-matching algorithm. These gaps remain visible in the registry until their corresponding work and validation are complete.


The 0.3 milestone adds the [historical float example](css1.md), explicit family
bindings, the supported font shorthand and root/body backgrounds. Its fixed-box
geometry is checked in Chromium/Firefox and against the native PDF raster. It
does not qualify the whole historical suite or CSS1. The next work remains text
spacing, indentation, transformation, complete font matching and the remaining
box/background/list contexts. Floats still need intrinsic sizing and pagination.
Release qualification records the exact candidate; inventory counts are not a
substitute for its receipts.
