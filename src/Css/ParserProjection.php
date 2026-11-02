<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Diagnostics;

/** Adapts parser limitations without changing the original declaration lexemes or offsets. */
final readonly class ParserProjection
{
    public function __construct(private Diagnostics $diagnostics) {}

    /** Input has already passed the cumulative UTF-8, byte and nesting budgets. */
    public function project(string $css): ?string
    {
        $projected = $css;
        $seenContent = false;
        /** @var list<bool> True only for an unescaped, unnested rgb() function. */
        $parentheses = [];
        $brackets = 0;
        for ($i = 0, $length = strlen($css); $i < $length;) {
            $char = $css[$i];
            if (str_contains(" \t\r\n\f", $char)) {
                ++$i;
                continue;
            }
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $seenContent = true;
                $quote = $char;
                for (++$i; $i < $length; ++$i) {
                    if ($css[$i] === '\\') {
                        ++$i;
                    } elseif ($css[$i] === $quote) {
                        ++$i;
                        break;
                    }
                }
                continue;
            }
            if ($char === '@') {
                [$name, $end] = $this->identifier($css, $i + 1);
                // Recovery may discard an unmatched delimiter before this directive.
                // A literal URL such as url(@charset.css) is not a directive.
                $afterName = $css[$end] ?? '';
                $encodingArgument = $afterName !== '' && (str_contains(" \t\r\n\f\"'", $afterName) || substr($css, $end, 2) === '/*');
                if ($name === 'charset' && (($parentheses === [] && $brackets === 0) || $encodingArgument)) {
                    $charsetEnd = $seenContent ? null : $this->utf8CharsetEnd($css, $end);
                    if ($charsetEnd === null) {
                        $this->diagnostics->unsupported('css.charset', 'Only one initial UTF-8 @charset declaration is supported; the stylesheet was ignored.');
                        return null;
                    }
                    $i = $charsetEnd;
                    $seenContent = true;
                    continue;
                }
            }
            $seenContent = true;
            if ($this->nameByte($char) || $char === '\\') {
                $start = $i;
                [, $i] = $this->identifier($css, $i);
                if (($css[$i] ?? '') === '(') {
                    $parentheses[] = $parentheses === [] && $brackets === 0 && strtolower(substr($css, $start, $i - $start)) === 'rgb';
                    ++$i;
                }
                continue;
            }
            if ($char === '(') {
                $parentheses[] = false;
            } elseif ($char === ')') {
                array_pop($parentheses);
            } elseif ($char === '[') {
                ++$brackets;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($char === '+' && end($parentheses) === true && $this->startsNumber($css, $i + 1)) {
                // One ASCII byte replaces one ASCII byte: all source positions remain exact.
                $projected[$i] = ' ';
            }
            ++$i;
        }
        return $projected;
    }

    private function startsNumber(string $css, int $offset): bool
    {
        return $this->digit($css[$offset] ?? '') || (($css[$offset] ?? '') === '.' && $this->digit($css[$offset + 1] ?? ''));
    }

    private function digit(string $char): bool
    {
        return $char !== '' && $char >= '0' && $char <= '9';
    }

    private function nameByte(string $char): bool
    {
        return $char !== '' && ($this->digit($char) || ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z') || $char === '-' || $char === '_' || ord($char) >= 0x80);
    }

    /**
     * Decode only enough of an identifier to recognize an encoding directive.
     * @return array{string,int}
     */
    private function identifier(string $css, int $offset): array
    {
        $name = '';
        for ($length = strlen($css); $offset < $length;) {
            $char = $css[$offset];
            if ($this->nameByte($char)) {
                $name .= $char;
                ++$offset;
            } elseif ($char === '\\') {
                ++$offset;
                $hex = '';
                while ($offset < $length && strlen($hex) < 6 && str_contains('0123456789abcdefABCDEF', $css[$offset])) {
                    $hex .= $css[$offset++];
                }
                if ($hex !== '') {
                    $code = hexdec($hex);
                    $name .= $code < 128 ? chr((int) $code) : '?';
                    if ($offset < $length && str_contains(" \t\r\n\f", $css[$offset])) {
                        $offset += substr($css, $offset, 2) === "\r\n" ? 2 : 1;
                    }
                } elseif ($offset < $length) {
                    $name .= $css[$offset++];
                }
            } else {
                break;
            }
        }
        return [strtolower($name), $offset];
    }

    private function utf8CharsetEnd(string $css, int $offset): ?int
    {
        $offset = $this->skipTrivia($css, $offset);
        $quote = $css[$offset] ?? '';
        if ($quote !== '"' && $quote !== "'") {
            return null;
        }
        $end = strpos($css, $quote, $offset + 1);
        if ($end === false || !in_array(strtolower(substr($css, $offset + 1, $end - $offset - 1)), ['utf-8', 'utf8'], true)) {
            return null;
        }
        $end = $this->skipTrivia($css, $end + 1);
        return ($css[$end] ?? '') === ';' ? $end + 1 : null;
    }

    private function skipTrivia(string $css, int $offset): int
    {
        for ($length = strlen($css); $offset < $length;) {
            if (str_contains(" \t\r\n\f", $css[$offset])) {
                ++$offset;
            } elseif (substr($css, $offset, 2) === '/*') {
                $end = strpos($css, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
            } else {
                break;
            }
        }
        return $offset;
    }
}
