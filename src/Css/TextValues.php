<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\TextIndent;

/** Text properties share signed lengths without changing non-negative box dimensions. */
final readonly class TextValues
{
    public function spacing(string $value, float $fontSize, float $rootSize): ?float
    {
        $value = strtolower(trim($value, " \t\r\n\f"));

        return $value === 'normal' ? null : (new Values())->points($value, $fontSize, $rootSize, true);
    }

    public function indent(string $value, float $fontSize, float $rootSize): TextIndent
    {
        $value = trim($value, " \t\r\n\f");
        $number = (new Values())->length($value, $fontSize, $rootSize, true, true);
        if (!is_float($number)) {
            throw new \LogicException('Expected a signed text length.');
        }

        return new TextIndent($number, str_ends_with($value, '%'));
    }

    public function serializeSpacing(?float $value): string
    {
        return $value === null ? 'normal' : $this->decimal($value) . 'pt';
    }

    public function serializeIndent(TextIndent $indent): string
    {
        return $this->decimal($indent->value) . ($indent->percentage ? '%' : 'pt');
    }

    /** Expand the exponent so computed values remain legal CSS without locale-dependent punctuation. */
    private function decimal(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }
        $number = sprintf('%.17g', $value);
        $decimalPoint = localeconv()['decimal_point'];
        if ($decimalPoint !== '' && $decimalPoint !== '.') {
            $number = str_replace($decimalPoint, '.', $number);
        }
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]+)$/D', $number, $parts) !== 1) {
            return $number;
        }
        $digits = $parts[2] . $parts[3];
        $point = strlen($parts[2]) + (int) $parts[4];
        if ($point <= 0) {
            return $parts[1] . '0.' . str_repeat('0', -$point) . $digits;
        }
        if ($point >= strlen($digits)) {
            return $parts[1] . $digits . str_repeat('0', $point - strlen($digits));
        }

        return $parts[1] . substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
}
