<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

/**
 * CSS1 5.2.5 weight values resolved to the nine-step numeric scale.
 *
 * bolder and lighter follow the font-independent chart of CSS 2.1 15.6, which
 * replaces the CSS1 definition based on the faces present in a family. The
 * chart is applied by range so that any inherited number has a defined result.
 */
final readonly class FontWeights
{
    public function isValue(string $value): bool
    {
        $value = $this->normalize($value);

        return in_array($value, ['normal', 'bold', 'bolder', 'lighter'], true) || preg_match('/^[1-9]00$/D', $value) === 1;
    }

    public function resolve(string $value, int $inherited): int
    {
        $value = $this->normalize($value);

        return match (true) {
            $value === 'normal' => 400,
            $value === 'bold' => 700,
            $value === 'bolder' => match (true) {
                $inherited < 350 => 400,
                $inherited < 550 => 700,
                default => 900,
            },
            $value === 'lighter' => match (true) {
                $inherited < 550 => 100,
                $inherited < 750 => 400,
                default => 700,
            },
            preg_match('/^[1-9]00$/D', $value) === 1 => (int) $value,
            default => throw new \InvalidArgumentException('Unsupported font weight.'),
        };
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value, " \t\r\n\f"));
    }
}
