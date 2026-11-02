# Security policy

[English](SECURITY.md) · [Français](SECURITY.fr.md)

This policy describes the security boundaries of `pdftoolkit/html-to-pdf`.
Read it with the [usage and resource policies](docs/usage.md) and the
[supported input profile](docs/coverage.md). The library is a PHP conversion
component, not a sandbox or a document security certification service.

## Explicit resources and external actions

The calling application supplies HTML/CSS strings, font bytes and image bytes.
The conversion pipeline does not fetch network resources, resolve filesystem
paths, discover system fonts, launch external programs or execute JavaScript.
`ResourceCatalog` keys are exact, case-sensitive identifiers. URL-shaped keys,
relative paths and `data:` strings do not trigger a download or file read;
images, linked stylesheets and imported stylesheets must already be present in
the catalog. `HtmlSource::baseUri` and each imported sheet's URI only resolve
stylesheet identities; they do not authorize access to those destinations.
Imports have cumulative count, depth and byte limits, and active cycles are
diagnosed. Unrecognized stylesheet encodings cannot trigger transcoding.

File reads, downloads and PDF writes in an application's integration code remain
that application's responsibility. Apply its authorization and input limits
before loading bytes or constructing resource objects. In particular, do not
turn arbitrary document resource names into automatic file reads or requests.

Supported absolute ASCII HTTP, HTTPS and mailto text links become PDF URI
annotations. They are not followed during conversion, but a PDF viewer may open
their destinations when a reader activates them. Apply any destination policy
required by your application before conversion. JavaScript actions, embedded
scripts, form submission and internal link destinations are outside the input
profile; this is not an HTML sanitization API.

## Resource and process limits

`ConversionLimits` bounds input, CSS work, nodes, nesting, text, pages, drawing
commands, resources and output. The full defaults are listed in the
[usage guide](docs/usage.md#limits). Each conversion has its own work counters.
Catalog accounting includes unused images and stylesheets. The renderer also
checks cumulative font/image bytes and image pixels used by the output. Dotted
and dashed borders also consume a cumulative pattern-work budget before output,
including fragments; tiny widths cannot create unlimited viewer work.

`ImageAsset` checks encoded bytes and dimensions before embedding.
`FontLimits` independently bounds each font's bytes, layout records, shaping
operations, nesting and glyph output. Fonts are parsed when their objects are
constructed, and layout tables are prepared when needed; reusable font objects
may retain these prepared tables. Font parsing and preparation can use much
more memory than the original bytes. The measured Lato examples require about
52 MiB per prepared face; this is a fixture measurement, not an upper bound for
other fonts.

The PDF byte limit is checked **after serialization**. Neither it nor the other
library budgets imposes a hard process memory limit or wall-clock deadline.
For untrusted input, configure application worker memory/time limits and bound
concurrency and the number of retained resource objects. Raising a library
limit does not add support for an absent feature or guarantee that a process
will have sufficient resources. Keep PHP and Composer dependencies updated.

## Errors and diagnostics

`UnsupportedPolicy::Reject` is the default: recognized unsupported features
stop conversion. `UnsupportedPolicy::Report` intentionally permits documented
approximations and returns diagnostics that the application must review.
For example, an unsupported HTML link target is removed with a diagnostic in
Report mode; unsafe URI annotations are never authorized by that policy.
Missing resources, absent glyphs or font variants, unsupported shaping,
indivisible overflow and exhausted limits can still stop conversion in either
mode. An exception produces no successful partial `ConversionResult`.

Diagnostics and exception messages may contain document-derived values. Escape
them for the destination UI and avoid logging confidential document content.
Successful conversion, tagged structure and passing sample validations do not
establish complete HTML/CSS conformance, PDF/UA or PDF/A certification, or the
absence of vulnerabilities.

## Reporting a vulnerability

Contact the repository owner privately at
[contact@roukmoute.fr](mailto:contact@roukmoute.fr), the address recorded in
`composer.json`. Include the package version or commit, PHP version, relevant
limits, expected behavior and a minimal reproduction without personal data or
credentials. Explain any observed resource exhaustion or external action.
Avoid posting sensitive payloads in a public issue before coordinating with
the maintainer. No response-time or remediation deadline is promised here.
