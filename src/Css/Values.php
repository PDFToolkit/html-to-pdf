<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\Length;

/** Narrow, explicit supported CSS value grammars. */
final class Values
{
    public function length(string $value, float $fontSize, float $rootSize, bool $percentage = false, bool $negative = false): Length|float
    {
        if (preg_match('/^([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+))(px|pt|pc|mm|cm|in|em|rem|%)?$/iD', trim($value), $parts) !== 1) {
            throw new \InvalidArgumentException('Unsupported length: ' . $value);
        }
        $number = (float) $parts[1];
        $unit = strtolower($parts[2] ?? '');
        if (!is_finite($number) || abs($number) > 1000000 || (!$negative && $number < 0) || ($unit === '' && $number !== 0.0) || ($unit === '%' && !$percentage)) {
            throw new \InvalidArgumentException('Unsupported length range or unit: ' . $value);
        }
        $factor = match ($unit) {
            'px' => .75, 'pc' => 12, 'mm' => 72 / 25.4, 'cm' => 72 / 2.54, 'in' => 72, 'em' => $fontSize, 'rem' => $rootSize, default => 1
        };
        return $negative ? $number * $factor : new Length($number * $factor, $unit === '%');
    }

    public function points(string $value, float $fontSize, float $rootSize, bool $negative = false): float
    {
        $length = $this->length($value, $fontSize, $rootSize, false, $negative);
        return $length instanceof Length ? $length->value : $length;
    }

    public function color(string $value, Color $current): ?Color
    {
        $value = strtolower(trim($value));
        if ($value === 'transparent') {
            return null;
        }
        if ($value === 'currentcolor') {
            return $current;
        }
        $names = ['black' => '000000', 'silver' => 'c0c0c0', 'gray' => '808080', 'grey' => '808080', 'white' => 'ffffff', 'maroon' => '800000', 'red' => 'ff0000', 'purple' => '800080', 'fuchsia' => 'ff00ff', 'green' => '008000', 'lime' => '00ff00', 'olive' => '808000', 'yellow' => 'ffff00', 'navy' => '000080', 'blue' => '0000ff', 'teal' => '008080', 'aqua' => '00ffff', 'orange' => 'ffa500'];
        if (isset($names[$value])) {
            $value = '#' . $names[$value];
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/D', $value, $match) === 1) {
            $hex = $match[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return new Color(hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
        }
        if (preg_match('/^rgb\(\s*([0-9.]+%?)\s*,\s*([0-9.]+%?)\s*,\s*([0-9.]+%?)\s*\)$/D', $value, $match) === 1) {
            $values = [];
            foreach (array_slice($match, 1) as $part) {
                $values[] = min(1, (float) $part / (str_ends_with($part, '%') ? 100 : 255));
            }
            return new Color($values[0], $values[1], $values[2]);
        }
        throw new \InvalidArgumentException('Unsupported color: ' . $value);
    }

    /** @param list<string> $allowed */
    public function keyword(string $value, array $allowed): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported keyword: ' . $value);
        }
        return $value;
    }

    public function positiveInteger(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected a bounded positive integer.');
        }
        return (int) $value;
    }
}
