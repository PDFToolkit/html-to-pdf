<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\Color;

/** Serialize computed color channels without locale dependence or CSS exponent syntax. */
final readonly class ColorFormatter
{
    public function format(?Color $color): string
    {
        if ($color === null) {
            return 'transparent';
        }
        return 'rgb(' . $this->decimal($color->red * 100) . '%,' . $this->decimal($color->green * 100) . '%,' . $this->decimal($color->blue * 100) . '%)';
    }

    private function decimal(float $number): string
    {
        if ($number === 0.0) {
            return '0';
        }
        // Seventeen significant digits preserve a finite binary64 value. Expanding
        // its exponent retains subnormal channels instead of rounding them to zero.
        $numberText = sprintf('%.17g', $number);
        $decimalPoint = localeconv()['decimal_point'];
        if ($decimalPoint !== '' && $decimalPoint !== '.') {
            $numberText = str_replace($decimalPoint, '.', $numberText);
        }
        if (preg_match('/^([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]+)$/D', $numberText, $parts) !== 1) {
            return $numberText;
        }
        $digits = $parts[1] . $parts[2];
        $point = strlen($parts[1]) + (int) $parts[3];
        if ($point <= 0) {
            return '0.' . str_repeat('0', -$point) . $digits;
        }
        if ($point >= strlen($digits)) {
            return $digits . str_repeat('0', $point - strlen($digits));
        }
        return substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
}
