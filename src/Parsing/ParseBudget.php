<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Parsing;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;

/** Cumulative work accounting owned by one conversion. */
final class ParseBudget
{
    private int $nodes = 0;
    private int $characters = 0;
    private int $cssBytes = 0;
    private int $rules = 0;
    private int $selectorWork = 0;
    private int $imports = 0;

    public function __construct(public readonly ConversionLimits $limits) {}

    public function input(string $html): void
    {
        if (strlen($html) > $this->limits->maxInputBytes) {
            throw new LimitExceeded('HTML input byte budget exhausted.');
        }
        $this->utf8($html);
    }

    public function utf8(string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new ConversionException('Input must be UTF-8 without null bytes.');
        }
    }

    public function node(int $depth): void
    {
        if (++$this->nodes > $this->limits->maxNodes || $depth > $this->limits->maxDepth) {
            throw new LimitExceeded('HTML node or depth budget exhausted.');
        }
    }

    public function text(string $text): void
    {
        $this->characters += mb_strlen($text, 'UTF-8');
        if ($this->characters > $this->limits->maxTextCharacters) {
            throw new LimitExceeded('HTML text budget exhausted.');
        }
    }

    /** Scan before recursive CSS parsing, respecting strings, escapes and comments. */
    public function css(string $css): void
    {
        $this->cssBytes += strlen($css);
        if ($this->cssBytes > $this->limits->maxCssBytes) {
            throw new LimitExceeded('Cumulative CSS byte budget exhausted.');
        }
        $this->utf8($css);
        $quote = null;
        $depth = 0;
        $comment = false;
        for ($i = 0, $length = strlen($css); $i < $length; ++$i) {
            $char = $css[$i];
            $next = $css[$i + 1] ?? '';
            if ($comment) {
                if ($char === '*' && $next === '/') {
                    $comment = false;
                    ++$i;
                }
                continue;
            }
            if ($char === '\\') {
                ++$i;
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = true;
                ++$i;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if (str_contains('{([', $char) && ++$depth > $this->limits->maxDepth) {
                throw new LimitExceeded('CSS nesting budget exhausted.');
            }
            if (str_contains('})]', $char)) {
                $depth = max(0, $depth - 1);
            }
            if (($char === '{' || $char === ';') && ++$this->rules > $this->limits->maxRules) {
                throw new LimitExceeded('CSS rule/declaration budget exhausted.');
            }
        }
    }

    public function selector(string $selector, int $elementCount): void
    {
        // Include selector complexity, not merely successful matches.
        $cost = max(1, strlen($selector)) * max(1, $elementCount);
        if ($cost > $this->limits->maxSelectorMatches - $this->selectorWork) {
            throw new LimitExceeded('CSS selector work budget exhausted.');
        }
        $this->selectorWork += $cost;
    }

    public function import(int $depth): void
    {
        if (++$this->imports > $this->limits->maxImports || $depth > $this->limits->maxImportDepth) {
            throw new LimitExceeded('CSS import count or depth budget exhausted.');
        }
    }
}
