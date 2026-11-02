<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Diagnostics;

/** Validate and expand one complete declaration before it can participate in the cascade. */
final readonly class Declarations
{
    private SpecifiedDeclarationValidator $validator;
    private BorderDeclarations $borders;

    public function __construct(private Diagnostics $diagnostics)
    {
        $this->validator = new SpecifiedDeclarationValidator();
        $this->borders = new BorderDeclarations();
    }

    /** @return array<string,string> */
    public function expand(string $property, string $value): array
    {
        $property = strtolower($property);
        $value = trim($value, " \t\r\n\f");
        try {
            $expanded = $this->expandSupported($property, $value);
            foreach ($expanded as $name => $part) {
                $this->validator->validate($name, $part);
            }
            return $expanded;
        } catch (\InvalidArgumentException $error) {
            $this->diagnostics->unsupported('css.value', $property . ': ' . $error->getMessage());
            return [];
        }
    }

    /** @return array<string,string> */
    private function expandSupported(string $property, string $value): array
    {
        if ($property === 'font') {
            return (new FontDeclarations())->expand($value);
        }
        $border = $this->borders->expand($property, $value);
        if ($border !== null) {
            return $border;
        }
        if (in_array($property, ['margin', 'padding'], true)) {
            $parts = preg_split('/[ \t\r\n\f]+/', strtolower($value)) ?: [];
            if ($value === '' || count($parts) > 4) {
                throw new \InvalidArgumentException('Expected one to four supported lengths.');
            }
            foreach ($parts as $part) {
                if (count($parts) !== 1 && in_array($part, ['inherit', 'initial', 'unset'], true)) {
                    throw new \InvalidArgumentException('A CSS-wide keyword must be the whole shorthand.');
                }
            }
            [$top, $right, $bottom, $left] = [$parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? $parts[1] ?? $parts[0]];
            return [$property . '-top' => $top, $property . '-right' => $right, $property . '-bottom' => $bottom, $property . '-left' => $left];
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
        $supported = ['font-family', 'font-variant', 'float', 'clear', 'display', 'font-size', 'line-height', 'font-weight', 'font-style', 'color', 'background-color', 'text-align', 'white-space', 'text-decoration', 'text-decoration-line', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'width', 'height', 'break-before', 'break-after', 'break-inside', 'orphans', 'widows', 'list-style-type', 'vertical-align', 'table-layout'];
        if (!in_array($property, $supported, true)) {
            $this->diagnostics->unsupported('css.property', 'Unsupported CSS declaration: ' . $property . ': ' . $value);
            return [];
        }
        if ($property === 'text-decoration-line') {
            $property = 'text-decoration';
        }
        return [$property => $value];
    }
}
