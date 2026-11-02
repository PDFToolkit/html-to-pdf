<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use PDF\HtmlToPdf\Exception\ConversionException;
use Sabberworm\CSS\Property\Declaration;

/** Retrieves original values before a third-party AST normalizes numbers or colors. */
final class DeclarationSource
{
    /** @var list<int> Unicode character offsets to original UTF-8 byte offsets. */
    private array $offsets = [];
    /** @var array<int,string> */
    private array $values = [];

    public function __construct(private readonly string $css)
    {
        for ($offset = 0, $length = strlen($css); $offset < $length;) {
            $this->offsets[] = $offset;
            $byte = ord($css[$offset]);
            $offset += $byte < 0x80 ? 1 : ($byte < 0xe0 ? 2 : ($byte < 0xf0 ? 3 : 4));
        }
        $this->offsets[] = strlen($css);
    }

    public function value(Declaration $declaration): string
    {
        // Sabberworm's column is an absolute Unicode offset just AFTER the property name.
        $position = $declaration->getColumnNumber();
        if ($position === null || !isset($this->offsets[$position])) {
            throw new ConversionException('CSS declaration has no reliable original source position.');
        }
        if (isset($this->values[$position])) {
            return $this->values[$position];
        }
        $offset = $this->offsets[$position];
        $length = strlen($this->css);
        while ($offset < $length) {
            if (str_contains(" \t\r\n\f", $this->css[$offset])) {
                ++$offset;
            } elseif (substr($this->css, $offset, 2) === '/*') {
                $end = strpos($this->css, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
            } else {
                break;
            }
        }
        if (($this->css[$offset] ?? '') !== ':') {
            throw new ConversionException('CSS declaration source position does not precede its value.');
        }
        ++$offset;
        $result = '';
        $quote = null;
        $depth = 0;
        for (; $offset < $length; ++$offset) {
            $char = $this->css[$offset];
            if ($char === '\\') {
                $result .= $char;
                if (++$offset < $length) {
                    $result .= $this->css[$offset];
                }
                continue;
            }
            if ($quote !== null) {
                $result .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '/' && ($this->css[$offset + 1] ?? '') === '*') {
                $end = strpos($this->css, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 1;
                $result .= ' ';
                continue;
            } elseif ($depth === 0 && ($char === ';' || $char === '}')) {
                break;
            } elseif (str_contains('([{', $char)) {
                ++$depth;
            } elseif (str_contains(')]}', $char)) {
                $depth = max(0, $depth - 1);
            }
            $result .= $char;
        }
        if ($declaration->getIsImportant()) {
            $result = preg_replace('/![ \t\r\n\f]*important[ \t\r\n\f]*$/iD', '', $result) ?? $result;
        }
        return $this->values[$position] = trim($result, " \t\r\n\f");
    }
}
