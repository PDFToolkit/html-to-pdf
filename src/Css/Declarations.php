<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Diagnostics;

/** Expand only the declared profile's shorthands, before cascade ordering. */
final readonly class Declarations
{
    public function __construct(private Diagnostics $diagnostics) {}

    /** @return array<string,string> */
    public function expand(string $property, string $value): array
    {
        $property = strtolower($property);
        $value = trim($value);
        if (in_array($property, ['margin', 'padding'], true)) {
            $parts = preg_split('/\s+/', strtolower($value)) ?: [];
            if (count($parts) < 1 || count($parts) > 4) {
                return $this->unsupported($property, $value);
            }
            [$top, $right, $bottom, $left] = [$parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? $parts[1] ?? $parts[0]];
            return [$property . '-top' => $top, $property . '-right' => $right, $property . '-bottom' => $bottom, $property . '-left' => $left];
        }
        if ($property === 'border') {
            if (in_array(strtolower($value), ['none', '0'], true)) {
                return ['border-width' => '0', 'border-style' => 'none', 'border-color' => 'currentcolor'];
            }
            if (preg_match('/^([0-9.]+(?:px|pt|mm|cm|in|em|rem)?)\s+(solid)\s+(.+)$/iD', $value, $matches) === 1) {
                return ['border-width' => $matches[1], 'border-style' => $matches[2], 'border-color' => $matches[3]];
            }
            return $this->unsupported($property, $value);
        }
        if ($property === 'background') {
            return ['background-color' => $value];
        }
        if ($property === 'page-break-before' || $property === 'page-break-after') {
            $property = str_replace('page-break-', 'break-', $property);
            $value = strtolower($value) === 'always' ? 'page' : $value;
        }
        if ($property === 'page-break-inside') {
            $property = 'break-inside';
        }
        $supported = ['display', 'font-size', 'line-height', 'font-weight', 'font-style', 'color', 'background-color', 'text-align', 'white-space', 'text-decoration', 'text-decoration-line', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-width', 'border-style', 'border-color', 'width', 'height', 'break-before', 'break-after', 'break-inside', 'orphans', 'widows', 'list-style-type', 'vertical-align', 'table-layout'];
        if (!in_array($property, $supported, true)) {
            return $this->unsupported($property, $value);
        }
        if ($property === 'text-decoration-line') {
            $property = 'text-decoration';
        }
        return [$property => $value];
    }

    /** @return array<string,string> */
    private function unsupported(string $property, string $value): array
    {
        $this->diagnostics->unsupported('css.property', 'Unsupported CSS declaration: ' . $property . ': ' . $value);
        return [];
    }
}
