<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

final readonly class ConversionLimits
{
    public function __construct(public int $maxInputBytes = 1048576, public int $maxCssBytes = 262144, public int $maxNodes = 10000, public int $maxDepth = 64, public int $maxTextCharacters = 100000, public int $maxRules = 1000, public int $maxSelectorMatches = 500000, public int $maxPages = 200, public int $maxCommands = 50000, public int $maxResourceBytes = 16777216, public int $maxImagePixels = 16000000, public int $maxOutputBytes = 33554432)
    {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Limits must be positive.');
            }
        }
    }
}
