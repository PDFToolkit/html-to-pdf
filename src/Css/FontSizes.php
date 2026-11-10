<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

/**
 * CSS1 5.2.6 size keywords, in PDF points.
 *
 * The absolute table is the one both browser witnesses compute for a 16px medium
 * (9, 10, 13, 16, 18, 24 and 32 CSS px). Relative keywords scale the parent's
 * computed size by a fixed ratio, as those witnesses do and CSS Fonts 4 allows.
 * The CSS1 example "larger from medium is large" is therefore not reproduced:
 * 12pt becomes 14.4pt, while large is 13.5pt.
 */
final readonly class FontSizes
{
    public const float RATIO = 1.2;

    private const array ABSOLUTE = [
        'xx-small' => 6.75,
        'x-small' => 7.5,
        'small' => 9.75,
        'medium' => 12.0,
        'large' => 13.5,
        'x-large' => 18.0,
        'xx-large' => 24.0,
    ];

    public function isKeyword(string $value): bool
    {
        $value = $this->normalize($value);

        return isset(self::ABSOLUTE[$value]) || $value === 'larger' || $value === 'smaller';
    }

    /** Null when the value is not a size keyword; lengths and percentages never use the table. */
    public function resolve(string $value, float $parentSize): ?float
    {
        $value = $this->normalize($value);
        if (isset(self::ABSOLUTE[$value])) {
            return self::ABSOLUTE[$value];
        }
        if ($value !== 'larger' && $value !== 'smaller') {
            return null;
        }
        if (!is_finite($parentSize) || $parentSize <= 0) {
            throw new \InvalidArgumentException('A relative font size needs a positive finite parent size.');
        }

        return $value === 'larger' ? $parentSize * self::RATIO : $parentSize / self::RATIO;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value, " \t\r\n\f"));
    }
}
