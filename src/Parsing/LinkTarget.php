<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Parsing;

use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Resource\CatalogUri;

/** Resolve an external annotation destination, without reading any referenced resource. */
final readonly class LinkTarget
{
    public function resolve(string $reference, ?string $base): string
    {
        if (str_contains($reference, '\\') || preg_match('/[^\x21-\x7e]/', $reference) === 1) {
            throw new ConversionException('Link targets must use ASCII URI characters without whitespace or backslashes.');
        }
        $fragmentOffset = strpos($reference, '#');
        $fragment = $fragmentOffset === false ? '' : substr($reference, $fragmentOffset);
        $resolved = (new CatalogUri())->resolve($reference, $base) . $fragment;
        if (str_contains($resolved, '\\') || preg_match('/[^\x21-\x7e]/', $resolved) === 1) {
            throw new ConversionException('The explicit link base must also resolve to an ASCII URI.');
        }
        $parts = parse_url($resolved);
        if ($parts === false) {
            throw new ConversionException('Malformed external link target.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (($scheme === 'http' || $scheme === 'https') && ($parts['host'] ?? '') !== '') {
            return $resolved;
        }
        if ($scheme === 'mailto' && ($parts['path'] ?? '') !== '') {
            return $resolved;
        }
        throw new ConversionException('Links require an absolute HTTP, HTTPS or mailto target, or an explicit supported base URI.');
    }
}
