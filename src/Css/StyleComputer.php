<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Style\Borders;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Style\Length;
use PDF\HtmlToPdf\Style\FontFamilyList;
use PDF\HtmlToPdf\Style\FontSlant;
use PDF\HtmlToPdf\Style\TextIndent;
use PDF\HtmlToPdf\Style\TextSpacing;

final readonly class StyleComputer
{
    private Values $values;
    private BorderComputer $borderComputer;

    public function __construct(private Diagnostics $diagnostics)
    {
        $this->values = new Values();
        $this->borderComputer = new BorderComputer();
    }

    /** @param array<string,string> $author */
    public function compute(string $tag, array $author, StyleState $parent, float $rootSize = 12): StyleState
    {
        $author = $this->expandLegacyBorders($author);
        $initial = array_replace($this->serialize(new ComputedStyle()), $this->borderComputer->defaults());
        $inherited = $this->serialize($parent->style);
        $inheritedNames = ['font-family', 'font-variant', 'font-size', 'line-height', 'font-weight', 'font-style', 'color', 'text-align', 'word-spacing', 'letter-spacing', 'text-indent', 'white-space', 'orphans', 'widows', 'list-style-type'];
        $css = array_replace($initial, array_intersect_key($inherited, array_flip($inheritedNames)), $this->defaults($tag));
        foreach ($author as $property => $value) {
            $keyword = strtolower(trim($value));
            $css[$property] = match ($keyword) {
                'initial' => $initial[$property],
                'inherit' => $inherited[$property],
                'unset' => in_array($property, $inheritedNames, true) ? $inherited[$property] : $initial[$property],
                default => $value,
            };
        }
        $fontFamily = $css['font-family'] === '' ? new FontFamilyList() : $this->value('font-family', fn(): FontFamilyList => (new FontFamilyParser())->parse($css['font-family']), $parent->style->fontFamily);
        $float = $this->value('float', fn(): string => $this->values->keyword($css['float'], ['none', 'left', 'right']), 'none');
        $clear = $this->value('clear', fn(): string => $this->values->keyword($css['clear'], ['none', 'left', 'right', 'both']), 'none');
        // Element defaults and author values are specified values; an undeclared size inherits the computed number itself.
        $declared = array_replace($this->defaults($tag), $author);
        $fontSize = $this->inheritedTextValue('font-size', $declared, fn(string $value): float => $this->fontSize($value, $parent->style->fontSize, $rootSize), $parent->style->fontSize, (new ComputedStyle())->fontSize);
        $lineFactor = $parent->lineHeightFactor;
        $lineHeight = $lineFactor !== null ? $lineFactor * $fontSize : $parent->style->lineHeight;
        if (isset($author['line-height'])) {
            $raw = strtolower(trim($author['line-height']));
            if ($raw === 'initial' || $raw === 'normal') {
                $lineFactor = 4 / 3;
                $lineHeight = $lineFactor * $fontSize;
            } elseif (!in_array($raw, ['inherit', 'unset'], true)) {
                $lineHeight = $this->value('line-height', function () use ($raw, $fontSize, $rootSize, &$lineFactor): float {
                    if (preg_match('/^\+?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $raw) === 1) {
                        $number = (float) $raw;
                        if ($number <= 0 || $number > 100) {
                            throw new \InvalidArgumentException('Unsupported line-height multiplier.');
                        }
                        $lineFactor = $number;
                        return $number * $fontSize;
                    }
                    $length = $this->values->length($raw, $fontSize, $rootSize, true);
                    if (!$length instanceof Length || $length->value <= 0) {
                        throw new \InvalidArgumentException('Line height must be positive.');
                    }
                    $lineFactor = null;
                    return $length->resolve($fontSize);
                }, $lineHeight);
            }
        }
        $color = $this->value('color', fn(): Color => $this->values->color($css['color'], $parent->style->color) ?? throw new \InvalidArgumentException('Transparent text is not supported.'), $parent->style->color);
        $background = $this->value('background-color', fn(): ?Color => $this->values->color($css['background-color'], $color), null);
        $display = $this->value('display', fn(): string => $this->values->keyword($css['display'], ['none', 'block', 'inline', 'list-item', 'table', 'table-row-group', 'table-header-group', 'table-footer-group', 'table-row', 'table-cell', 'table-caption']), $this->defaults($tag)['display'] ?? 'inline');
        if ($float !== 'none' && $display === 'inline') {
            $display = 'block';
        }
        $smallCaps = $this->inheritedTextValue('font-variant', $declared, fn(string $value): bool => $this->values->keyword($value, ['normal', 'small-caps']) === 'small-caps', $parent->style->smallCaps, false);
        // A child inherits the resulting weight, never the relative keyword.
        $fontWeight = $this->inheritedTextValue('font-weight', $declared, fn(string $value): int => (new FontWeights())->resolve($value, $parent->style->fontWeight), $parent->style->fontWeight, (new ComputedStyle())->fontWeight);
        $slant = $this->inheritedTextValue('font-style', $declared, fn(string $value): FontSlant => FontSlant::from($this->values->keyword($value, ['normal', 'italic', 'oblique'])), $parent->style->fontSlant(), FontSlant::Normal);
        $italic = $slant === FontSlant::Italic;
        $align = $this->value('text-align', fn(): string => $this->values->keyword($css['text-align'], ['left', 'center', 'right']), $parent->style->textAlign);
        $textValues = new TextValues();
        $textSpacing = new TextSpacing(
            $this->inheritedTextValue('letter-spacing', $author, fn(string $value): ?float => $textValues->spacing($value, $fontSize, $rootSize), $parent->style->textSpacing->letterSpacing, null),
            $this->inheritedTextValue('word-spacing', $author, fn(string $value): ?float => $textValues->spacing($value, $fontSize, $rootSize), $parent->style->textSpacing->wordSpacing, null),
        );
        $textIndent = $this->inheritedTextValue('text-indent', $author, fn(string $value): TextIndent => $textValues->indent($value, $fontSize, $rootSize), $parent->style->textIndent, new TextIndent());
        $whiteSpace = $this->value('white-space', fn(): string => $this->values->keyword($css['white-space'], ['normal', 'nowrap', 'pre', 'pre-wrap']), $parent->style->whiteSpace);
        $localUnderline = $this->value('text-decoration', fn(): bool => $this->values->keyword($css['text-decoration'], ['none', 'underline']) === 'underline', false);
        $underline = $parent->style->underline || $localUnderline;
        $margin = $this->edges('margin', $css, $fontSize, $rootSize);
        $padding = $this->edges('padding', $css, $fontSize, $rootSize);
        $borders = $this->value('border', fn(): Borders => $this->borderComputer->compute($css, $fontSize, $rootSize, $color), new Borders());
        // Keep the legacy uniform fields as projections of the top side; layout uses all four sides.
        $borderWidth = $borders->top->effectiveWidth();
        $borderColor = $borders->top->color ?? $color;
        $width = $this->dimension($css['width'], $fontSize, $rootSize, true);
        $height = $this->dimension($css['height'], $fontSize, $rootSize, false);
        $before = $this->value('break-before', fn(): string => $this->values->keyword($css['break-before'], ['auto', 'page', 'always']), 'auto');
        $after = $this->value('break-after', fn(): string => $this->values->keyword($css['break-after'], ['auto', 'page', 'always']), 'auto');
        $avoid = $this->value('break-inside', fn(): bool => $this->values->keyword($css['break-inside'], ['auto', 'avoid', 'avoid-page']) !== 'auto', false);
        $orphans = $this->value('orphans', fn(): int => $this->values->positiveInteger($css['orphans']), 2);
        $widows = $this->value('widows', fn(): int => $this->values->positiveInteger($css['widows']), 2);
        $listStyle = $this->value('list-style-type', fn(): string => $this->values->keyword($css['list-style-type'], ['disc', 'circle', 'square', 'decimal', 'none']), $parent->style->listStyleType);
        $vertical = $this->value('vertical-align', fn(): string => $this->values->keyword($css['vertical-align'], ['top', 'middle', 'bottom']), 'top');
        if (isset($author['vertical-align']) && $display !== 'table-cell') {
            $this->diagnostics->unsupported('css.context', 'vertical-align is supported on table cells only.');
            $vertical = 'top';
        }
        $tableLayout = $this->value('table-layout', fn(): string => $this->values->keyword($css['table-layout'], ['auto', 'fixed']), 'auto');
        return new StyleState(new ComputedStyle($display, $fontSize, $lineHeight, $fontWeight, $italic, $color, $background, $align, $whiteSpace, $underline, $margin, $padding, $borderWidth, $borderColor, $width, $height, $before, $after, $avoid, $orphans, $widows, $listStyle, $vertical, $tableLayout, $borders, $float, $clear, $fontFamily, $textSpacing, $textIndent, $slant, $smallCaps), $lineFactor);
    }

    /** Preserve the uniform border input accepted by the 0.1 computed-style API.
     * @param array<string,string> $author
     * @return array<string,string>
     */
    private function expandLegacyBorders(array $author): array
    {
        if (!isset($author['border-width']) && !isset($author['border-style']) && !isset($author['border-color'])) {
            return $author;
        }
        $result = [];
        $declarations = new Declarations($this->diagnostics);
        foreach ($author as $property => $value) {
            $result = array_replace($result, in_array($property, ['border-width', 'border-style', 'border-color'], true)
                ? $declarations->expand($property, $value) : [$property => $value]);
        }
        return $result;
    }

    /**
     * @template T
     * @param callable():T $parse
     * @param T $fallback
     * @return T
     */
    private function value(string $property, callable $parse, mixed $fallback): mixed
    {
        try {
            return $parse();
        } catch (\InvalidArgumentException $error) {
            $this->diagnostics->unsupported('css.value', $property . ': ' . $error->getMessage());
            return $fallback;
        }
    }

    /**
     * Inherit computed objects directly: absolute lengths must not be reinterpreted
     * at a descendant font size or rechecked against a specified-token limit.
     * @template T
     * @param array<string,string> $author
     * @param callable(string):T $parse
     * @param T $inherited
     * @param T $initial
     * @return T
     */
    private function inheritedTextValue(string $property, array $author, callable $parse, mixed $inherited, mixed $initial): mixed
    {
        if (!isset($author[$property])) {
            return $inherited;
        }
        $value = strtolower(trim($author[$property], " \t\r\n\f"));
        return match ($value) {
            'inherit', 'unset' => $inherited,
            'initial' => $initial,
            default => $this->value($property, static fn(): mixed => $parse($value), $inherited),
        };
    }

    /** Keywords index the UA table or scale the parent size; lengths and percentages bypass the table. */
    private function fontSize(string $value, float $parentSize, float $rootSize): float
    {
        $points = (new FontSizes())->resolve($value, $parentSize);
        if ($points === null) {
            $length = $this->values->length($value, $parentSize, $rootSize, true);
            if (!$length instanceof Length) {
                throw new \LogicException('Expected a positive length.');
            }
            $points = $length->resolve($parentSize);
        }
        if (!is_finite($points) || $points <= 0 || $points > 1000) {
            throw new \InvalidArgumentException('Font size must be between zero and 1000 points.');
        }

        return $points;
    }

    private function dimension(string $value, float $fontSize, float $rootSize, bool $percentage): ?Length
    {
        if (strtolower($value) === 'auto') {
            return null;
        }
        return $this->value($percentage ? 'width' : 'height', function () use ($value, $fontSize, $rootSize, $percentage): Length {
            $length = $this->values->length($value, $fontSize, $rootSize, $percentage);
            if (!$length instanceof Length) {
                throw new \LogicException('Expected a positive length.');
            }
            return $length;
        }, null);
    }

    /** @param array<string,string> $css */
    private function edges(string $property, array $css, float $fontSize, float $rootSize): Edges
    {
        $parts = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $name = $property . '-' . $side;
            $parts[] = $this->value($name, fn(): float => $this->values->points($css[$name], $fontSize, $rootSize), 0.0);
        }
        return new Edges(...$parts);
    }

    /** @return array<string,string> */
    private function defaults(string $tag): array
    {
        $display = match ($tag) {
            'html', 'body', 'dl', 'dt', 'dd', 'form', 'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'address', 'p', 'pre', 'blockquote', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr' => 'block',
            'li' => 'list-item', 'table' => 'table', 'thead' => 'table-header-group', 'tbody' => 'table-row-group', 'tfoot' => 'table-footer-group', 'tr' => 'table-row', 'td', 'th' => 'table-cell', 'caption' => 'table-caption',
            'head', 'style', 'link', 'meta', 'title', 'script', 'template' => 'none', default => 'inline',
        };
        $values = ['display' => $display];
        if (in_array($tag, ['th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $values['font-weight'] = '700';
        }
        if (in_array($tag, ['strong', 'b'], true)) {
            $values['font-weight'] = 'bolder';
        }
        if (in_array($tag, ['em', 'i', 'address'], true)) {
            $values['font-style'] = 'italic';
        }
        if ($tag === 'u') {
            $values['text-decoration'] = 'underline';
        }
        if ($tag === 'pre') {
            $values['white-space'] = 'pre';
        }
        if ($tag === 'ol') {
            $values['list-style-type'] = 'decimal';
        }
        if ($tag === 'ul') {
            $values['list-style-type'] = 'disc';
        }
        $sizes = ['h1' => '2em', 'h2' => '1.5em', 'h3' => '1.17em', 'h4' => '1em', 'h5' => '.83em', 'h6' => '.67em'];
        if (isset($sizes[$tag])) {
            $values['font-size'] = $sizes[$tag];
        }
        if (in_array($tag, ['p', 'pre', 'blockquote', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $values['margin-top'] = '.5em';
            $values['margin-bottom'] = '.5em';
        }
        if (in_array($tag, ['ul', 'ol', 'blockquote'], true)) {
            $values['padding-left'] = '24pt';
        }
        if ($tag === 'th') {
            $values['text-align'] = 'center';
        }
        if ($tag === 'hr') {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $values['border-' . $side . '-width'] = '1pt';
                $values['border-' . $side . '-style'] = 'solid';
            }
        }
        return $values;
    }

    /** @return array<string,string> */
    private function serialize(ComputedStyle $s): array
    {
        $color = (new ColorFormatter())->format(...);
        $textValues = new TextValues();
        $dimension = static fn(?Length $length): string => $length === null ? 'auto' : $length->value . ($length->percentage ? '%' : 'pt');
        $values = ['font-family' => $s->fontFamily->css(), 'font-variant' => $s->smallCaps ? 'small-caps' : 'normal', 'float' => $s->float, 'clear' => $s->clear, 'display' => $s->display, 'font-size' => $s->fontSize . 'pt', 'line-height' => $s->lineHeight . 'pt', 'font-weight' => (string) $s->fontWeight, 'font-style' => $s->fontSlant()->value, 'color' => $color($s->color), 'background-color' => $s->background === null ? 'transparent' : $color($s->background), 'text-align' => $s->textAlign, 'letter-spacing' => $textValues->serializeSpacing($s->textSpacing->letterSpacing), 'word-spacing' => $textValues->serializeSpacing($s->textSpacing->wordSpacing), 'text-indent' => $textValues->serializeIndent($s->textIndent), 'white-space' => $s->whiteSpace, 'text-decoration' => $s->underline ? 'underline' : 'none', 'width' => $dimension($s->width), 'height' => $dimension($s->height), 'break-before' => $s->breakBefore, 'break-after' => $s->breakAfter, 'break-inside' => $s->breakInsideAvoid ? 'avoid' : 'auto', 'orphans' => (string) $s->orphans, 'widows' => (string) $s->widows, 'list-style-type' => $s->listStyleType, 'vertical-align' => $s->verticalAlign, 'table-layout' => $s->tableLayout];
        foreach (['margin', 'padding'] as $name) {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $values[$name . '-' . $side] = $s->$name->$side . 'pt';
            }
        }
        return array_replace($values, $this->borderComputer->serialize($s->borderEdges()));
    }
}
