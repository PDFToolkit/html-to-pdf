# Contributor instructions

- Implement HTML-to-PDF in this optional PHP module, using explicit objects and document-scoped processing. Keep the PDF core unchanged unless its own PDF mechanisms require a separate correction.
- This is an independent MIT implementation. iText pdfHTML is a feature reference, not a source to copy or translate. Record primary HTML/CSS references and implemented coverage accurately.
- Parse HTML5 with an explicit parser. Separate source objects, CSS cascade, computed styles, pagination, drawing operations and PDF serialization.
- Use bounded input and cumulative work/resource limits. No implicit filesystem, network or process access, no JavaScript execution. Resources and embeddable fonts are supplied explicitly as bytes.
- Preserve selectable Unicode text and PDF structure; never claim PDF/A or PDF/UA certification without the relevant complete validation.
- Unsupported behavior must produce a diagnostic or exception. Do not silently replace advanced layout with a misleading appearance.
- PHP 8.3+; validate the exact candidate on PHP 8.3, 8.4 and 8.5, with meaningful unit tests, independent qpdf/pypdf checks and visual rendering.
- Keep English/French guides, examples and coverage up to date. Use atomic commits, stable dependencies, immutable annotated versions and exact-candidate CI before publication.
