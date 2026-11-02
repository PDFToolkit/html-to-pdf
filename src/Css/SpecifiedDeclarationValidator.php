<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\Length;

/** Validate the supported specified grammar without substituting a parent or layout context. */
final readonly class SpecifiedDeclarationValidator
{
    private Values $values;

    public function __construct()
    {
        $this->values = new Values();
    }

    public function validate(string $property, string $value): void
    {
        $value = strtolower(trim($value, " \t\r\n\f"));
        if (in_array($value, ['inherit', 'initial', 'unset'], true)) {
            return;
        }
        if (preg_match('/^(margin|padding)-(top|right|bottom|left)$/D', $property) === 1) {
            $this->length($value);
            return;
        }
        if (preg_match('/^border-(top|right|bottom|left)-(width|style|color)$/D', $property, $parts) === 1) {
            $this->border($parts[2], $value);
            return;
        }
        switch ($property) {
            case 'font-family':
                (new FontFamilyParser())->parse($value);
                return;
            case 'font-size':
                $this->length($value, true, true);
                return;
            case 'line-height':
                if ($value === 'normal') {
                    return;
                }
                if (preg_match('/^[+]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D', $value) === 1) {
                    $number = (float) $value;
                    if ($number <= 0 || $number > 100 || !is_finite($number)) {
                        throw new \InvalidArgumentException('Line height multiplier must be positive and at most 100.');
                    }
                    return;
                }
                $this->length($value, true, true);
                return;
            case 'width':
            case 'height':
                if ($value !== 'auto') {
                    $this->length($value, $property === 'width');
                }
                return;
            case 'color':
            case 'background-color':
                $color = $this->values->color($value, new Color());
                if ($property === 'color' && $color === null) {
                    throw new \InvalidArgumentException('Transparent text is outside the supported profile.');
                }
                return;
            case 'font-weight':
                if (!in_array($value, ['normal', 'bold'], true) && preg_match('/^[1-9]00$/D', $value) !== 1) {
                    throw new \InvalidArgumentException('Unsupported font weight.');
                }
                return;
            case 'orphans':
            case 'widows':
                $this->values->positiveInteger($value);
                return;
        }
        $allowed = match ($property) {
            'font-variant' => ['normal'],
            'float' => ['none', 'left', 'right'],
            'clear' => ['none', 'left', 'right', 'both'],
            'display' => ['none', 'block', 'inline', 'list-item', 'table', 'table-row-group', 'table-header-group', 'table-footer-group', 'table-row', 'table-cell', 'table-caption'],
            'font-style' => ['normal', 'italic'],
            'text-align' => ['left', 'center', 'right'],
            'white-space' => ['normal', 'nowrap', 'pre', 'pre-wrap'],
            'text-decoration' => ['none', 'underline'],
            'break-before', 'break-after' => ['auto', 'page', 'always'],
            'break-inside' => ['auto', 'avoid', 'avoid-page'],
            'list-style-type' => ['disc', 'circle', 'square', 'decimal', 'none'],
            'vertical-align' => ['top', 'middle', 'bottom'],
            'table-layout' => ['auto', 'fixed'],
            default => throw new \InvalidArgumentException('Unsupported CSS property: ' . $property),
        };
        $this->values->keyword($value, $allowed);
    }

    private function length(string $value, bool $percentage = false, bool $positive = false): void
    {
        // Placeholder factors check syntax only; font-relative values are resolved later.
        $length = $this->values->length($value, 1, 1, $percentage);
        if (!$length instanceof Length || ($positive && $length->value <= 0)) {
            throw new \InvalidArgumentException('This property requires a positive supported length.');
        }
    }

    private function border(string $part, string $value): void
    {
        if ($part === 'width') {
            if (!in_array($value, ['thin', 'medium', 'thick'], true)) {
                $this->length($value);
            }
        } elseif ($part === 'style') {
            $this->values->keyword($value, ['none', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset']);
        } else {
            $this->values->color($value, new Color());
        }
    }
}
