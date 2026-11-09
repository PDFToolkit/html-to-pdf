<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** Computed text spacing in points; null preserves the CSS normal keyword. */
final readonly class TextSpacing
{
    public function __construct(public ?float $letterSpacing = null, public ?float $wordSpacing = null)
    {
        foreach ([$letterSpacing, $wordSpacing] as $value) {
            if ($value !== null && !is_finite($value)) {
                throw new \InvalidArgumentException('Text spacing must be finite.');
            }
        }
    }

    public function cacheKey(): string
    {
        $key = static fn(?float $value): string => $value === null ? 'normal' : bin2hex(pack('E', $value === 0.0 ? 0.0 : $value));

        return $key($this->letterSpacing) . ':' . $key($this->wordSpacing);
    }
}
