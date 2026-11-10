<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

/** Expand every component together; unsupported values invalidate the whole shorthand. */
final readonly class FontDeclarations
{
    /** @return array<string,string> */
    public function expand(string $value): array
    {
        $result = ['font-style' => 'normal', 'font-variant' => 'normal', 'font-weight' => 'normal', 'font-size' => '', 'line-height' => 'normal', 'font-family' => ''];
        $keyword = strtolower(trim($value));
        if (in_array($keyword, ['inherit', 'initial', 'unset'], true)) {
            return array_fill_keys(array_keys($result), $keyword);
        }
        $tokens = (new FontValueTokenizer())->tokenize($value);
        $sizes = new FontSizes();
        $normal = 0;
        $set = [];
        $index = 0;
        for (; $index < count($tokens); ++$index) {
            $token = $tokens[$index];
            $part = strtolower($token->value);
            if ($token->quoted) {
                throw new \InvalidArgumentException('A font shorthand needs a size before its family.');
            }
            if ($sizes->isKeyword($part) || preg_match('/^[+]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|pt|pc|mm|cm|in|em|rem|%)$/iD', $part) === 1) {
                $result['font-size'] = $part;
                ++$index;
                break;
            }
            if ($part === 'normal') {
                ++$normal;
            } else {
                $property = match (true) {
                    $part === 'italic' || $part === 'oblique' => 'font-style',
                    $part === 'small-caps' => 'font-variant',
                    (new FontWeights())->isValue($part) => 'font-weight',
                    default => throw new \InvalidArgumentException('Unsupported font shorthand component: ' . $part),
                };
                if (isset($set[$property])) {
                    throw new \InvalidArgumentException('A font shorthand component occurs more than once.');
                }
                $set[$property] = true;
                $result[$property] = $part;
            }
            if ($normal + count($set) > 3) {
                throw new \InvalidArgumentException('Too many optional font shorthand components.');
            }
        }
        if ($result['font-size'] === '') {
            throw new \InvalidArgumentException('A font shorthand needs a supported positive font size.');
        }
        if (isset($tokens[$index]) && !$tokens[$index]->quoted && $tokens[$index]->value === '/') {
            ++$index;
            if (!isset($tokens[$index]) || $tokens[$index]->quoted || in_array(strtolower($tokens[$index]->value), ['inherit', 'initial', 'unset'], true)) {
                throw new \InvalidArgumentException('Expected a line height after the font slash; a CSS-wide keyword must be the whole shorthand.');
            }
            $result['line-height'] = $tokens[$index++]->value;
        }
        $result['font-family'] = (new FontFamilyParser())->tokens(array_slice($tokens, $index))->css();
        return $result;
    }
}
