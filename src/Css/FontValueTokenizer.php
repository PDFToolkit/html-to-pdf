<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

/** Small bounded lexer for font lists and the CSS1 font shorthand. */
final class FontValueTokenizer
{
    /** @return list<FontValueToken> */
    public function tokenize(string $value): array
    {
        $tokens = [];
        for ($offset = 0, $length = strlen($value); $offset < $length;) {
            $char = $value[$offset];
            if (str_contains(" \t\r\n\f", $char)) {
                ++$offset;
                continue;
            }
            if ($char === ',' || $char === '/') {
                $tokens[] = new FontValueToken($char);
                ++$offset;
                continue;
            }
            $quoted = $char === '"' || $char === "'";
            $quote = $quoted ? $value[$offset++] : null;
            $part = '';
            $closed = !$quoted;
            while ($offset < $length) {
                $char = $value[$offset];
                if ($quote !== null && $char === $quote) {
                    ++$offset;
                    $closed = true;
                    break;
                }
                if ($quote === null && str_contains(" \t\r\n\f,/", $char)) {
                    break;
                }
                if ($char === '\\') {
                    $part .= $this->escape($value, $offset, $quoted);
                    continue;
                }
                if (str_contains("\r\n\f", $char) || ($quote === null && ($char === '"' || $char === "'"))) {
                    throw new \InvalidArgumentException('Malformed font token.');
                }
                $part .= $char;
                ++$offset;
            }
            if (!$closed || $part === '') {
                throw new \InvalidArgumentException('Empty or unterminated font token.');
            }
            $tokens[] = new FontValueToken($part, $quoted);
        }
        return $tokens;
    }

    private function escape(string $value, int &$offset, bool $quoted): string
    {
        $length = strlen($value);
        if (++$offset >= $length) {
            throw new \InvalidArgumentException('Incomplete font escape.');
        }
        if (str_contains("\r\n\f", $value[$offset])) {
            if (!$quoted) {
                throw new \InvalidArgumentException('Newlines cannot escape a family identifier.');
            }
            $offset += substr($value, $offset, 2) === "\r\n" ? 2 : 1;
            return '';
        }
        $hex = '';
        while ($offset < $length && strlen($hex) < 6 && str_contains('0123456789abcdefABCDEF', $value[$offset])) {
            $hex .= $value[$offset++];
        }
        if ($hex === '') {
            return $value[$offset++];
        }
        if ($offset < $length && str_contains(" \t\r\n\f", $value[$offset])) {
            $offset += substr($value, $offset, 2) === "\r\n" ? 2 : 1;
        }
        $code = (int) hexdec($hex);
        return mb_chr($code === 0 || $code > 0x10ffff || ($code >= 0xd800 && $code <= 0xdfff) ? 0xfffd : $code, 'UTF-8');
    }
}
