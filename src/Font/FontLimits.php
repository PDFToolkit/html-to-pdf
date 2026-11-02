<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

use PDFToolkit\OpenType\Sfnt\ReadLimits;

/** Per-font parsing and per-run shaping budgets; the converter also limits total work. */
final readonly class FontLimits
{
    public function __construct(
        public int $maxBytes = 16_777_216,
        public int $maxTextBytes = 262_144,
        public int $maxGlyphs = 65_535,
        public int $maxLayoutRecords = 262_144,
        public int $maxSubstitutionOperations = 1_048_576,
        public int $maxLookupNesting = 32,
    ) {
        if ($maxTextBytes < 1 || $maxTextBytes > 1_048_576 || $maxGlyphs > 65_535) {
            throw new \InvalidArgumentException('Font text and glyph budgets must fit one bounded PDF repertoire.');
        }
        $this->readingLimits();
    }

    public function readingLimits(): ReadLimits
    {
        return new ReadLimits(
            maxBytes: $this->maxBytes,
            maxLayoutRecords: $this->maxLayoutRecords,
            maxOutputGlyphs: $this->maxGlyphs,
            maxSubstitutionOperations: $this->maxSubstitutionOperations,
            maxLookupNesting: $this->maxLookupNesting,
        );
    }
}
