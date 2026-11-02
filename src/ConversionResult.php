<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

/** Conversion bytes and diagnostics belong to one completed document. */
final readonly class ConversionResult
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(public string $pdf, public int $pageCount, public array $diagnostics = []) {}
}
