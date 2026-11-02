<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\BorderStyle;
use PDF\HtmlToPdf\Style\Color;

/** Atomic shorthand expansion: no longhand escapes when any component is invalid. */
final readonly class BorderDeclarations
{
    /** @return array<string,string>|null Null identifies an unrelated property. */
    public function expand(string $property, string $value): ?array
    {
        if (preg_match('/^border(?:-(top|right|bottom|left))?(?:-(width|style|color))?$/D', strtolower($property), $matches) !== 1) {
            return null;
        }
        $side = $matches[1] ?? '';
        $component = $matches[2] ?? '';
        $value = strtolower(trim($value, " \t\r\n\f"));
        $sides = $side === '' ? ['top', 'right', 'bottom', 'left'] : [$side];
        if (in_array($value, ['inherit', 'initial', 'unset'], true)) {
            $components = $component === '' ? ['width', 'style', 'color'] : [$component];
            $result = [];
            foreach ($sides as $edge) {
                foreach ($components as $part) {
                    $result['border-' . $edge . '-' . $part] = $value;
                }
            }
            return $result;
        }
        $parts = $this->tokens($value);
        if ($component !== '') {
            if (count($parts) > ($side === '' ? 4 : 1)) {
                throw new \InvalidArgumentException('A border component requires one to four values.');
            }
            foreach ($parts as $part) {
                $this->validate($component, $part);
            }
            $tuple = [$parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? $parts[1] ?? $parts[0]];
            $result = [];
            foreach ($sides as $index => $edge) {
                $result['border-' . $edge . '-' . $component] = $tuple[$index];
            }
            return $result;
        }
        $resolved = ['width' => 'medium', 'style' => 'none', 'color' => 'currentcolor'];
        $seen = [];
        foreach ($parts as $part) {
            $kind = $this->kind($part);
            if (isset($seen[$kind])) {
                throw new \InvalidArgumentException('Duplicate border shorthand component.');
            }
            $seen[$kind] = true;
            $resolved[$kind] = $part;
        }
        $result = [];
        foreach ($sides as $edge) {
            foreach ($resolved as $part => $setting) {
                $result['border-' . $edge . '-' . $part] = $setting;
            }
        }
        return $result;
    }

    /** @return non-empty-list<string> */
    private function tokens(string $value): array
    {
        if ($value === '' || preg_match('/[()]/', preg_replace('/rgb\([^()]*\)/i', 'rgb', $value) ?? '') === 1) {
            throw new \InvalidArgumentException('Invalid border value.');
        }
        preg_match_all('/\G[ \t\r\n\f]*(rgb\([^()]*\)|[^ \t\r\n\f()]+)(?=[ \t\r\n\f]|$)/i', $value, $matches);
        $parts = $matches[1];
        if ($parts === [] || count($parts) > 4 || implode('', $matches[0]) !== $value) {
            throw new \InvalidArgumentException('Invalid border component count or token separation.');
        }
        return $parts;
    }

    private function kind(string $value): string
    {
        if (BorderStyle::tryFrom($value) !== null) {
            return 'style';
        }
        try {
            $this->validate('width', $value);
            return 'width';
        } catch (\InvalidArgumentException) {
            $this->validate('color', $value);
            return 'color';
        }
    }

    private function validate(string $component, string $value): void
    {
        $values = new Values();
        if ($component === 'style') {
            if (BorderStyle::tryFrom($value) === null) {
                throw new \InvalidArgumentException('Invalid border style.');
            }
        } elseif ($component === 'width') {
            if (!in_array($value, ['thin', 'medium', 'thick'], true)) {
                $values->points($value, 12, 12);
            }
        } else {
            $values->color($value, new Color());
        }
    }
}
