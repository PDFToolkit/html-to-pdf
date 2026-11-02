<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Parsing;

/** Adapt recognized standards-mode HTML4 metadata to the HTML5 parser's doctype grammar. */
final readonly class HtmlDoctypeProjection
{
    public function project(string $html): string
    {
        $offset = str_starts_with($html, "\xef\xbb\xbf") ? 3 : 0;
        $length = strlen($html);
        while ($offset < $length) {
            if (str_contains(" \t\r\n\f", $html[$offset])) {
                ++$offset;
            } elseif (substr($html, $offset, 4) === '<!--') {
                $end = strpos($html, '-->', $offset + 4);
                if ($end === false) {
                    return $html;
                }
                $offset = $end + 3;
            } else {
                break;
            }
        }
        $pattern = '~\G<!DOCTYPE[ \t\r\n\f]+html[ \t\r\n\f]+PUBLIC[ \t\r\n\f]+(["\'])([^"\'<>]+)\1[ \t\r\n\f]+(["\'])([^"\'<>]+)\3[ \t\r\n\f]*>~i';
        if (preg_match($pattern, $html, $match, 0, $offset) !== 1) {
            return $html;
        }
        $known = [
            '-//W3C//DTD HTML 4.0//EN' => 'http://www.w3.org/TR/REC-html40/strict.dtd',
            '-//W3C//DTD HTML 4.01//EN' => 'http://www.w3.org/TR/html4/strict.dtd',
        ];
        if (($known[$match[2]] ?? null) !== $match[4]) {
            return $html;
        }
        // The public/system identifiers are metadata, never entities or a request to fetch a DTD.
        // Retain byte length and newline count so subsequent source diagnostics remain useful.
        $newlines = preg_replace('/[^\r\n]/', '', $match[0]) ?? '';
        $replacement = '<!DOCTYPE html>' . $newlines . str_repeat(' ', strlen($match[0]) - 15 - strlen($newlines));
        return substr($html, 0, $offset) . $replacement . substr($html, $offset + strlen($match[0]));
    }
}
