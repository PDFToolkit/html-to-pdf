<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontStyle;
use PDF\HtmlToPdf\Style\ComputedStyle;

/** Horizontal inline flow, measured with exactly the font shaper used by serialization. */
final class InlineFormatter
{
    private int $measuredBytes = 0;
    /** @var array<string, float> */
    private array $measurements = [];
    private int $cachedBytes = 0;
    private int $lineCount = 0;
    private int $commandCount = 0;

    public function __construct(private readonly ConversionOptions $options) {}

    /** @param list<InlinePiece> $pieces
     * @return list<FlowItem>
     */
    public function format(array $pieces, float $x, float $width, ComputedStyle $style, string $paragraph): array
    {
        /** @var list<InlinePiece> $line */
        $line = [];
        $lines = [];
        $pendingSpace = null;
        $explicitBreak = false;
        foreach ($pieces as $piece) {
            if ($piece->lineBreak) {
                $lines[] = $this->line($line, $x, $width, $style);
                $line = [];
                $pendingSpace = null;
                $explicitBreak = true;
                continue;
            }
            if ($piece->image !== null) {
                $candidate = $line;
                if ($pendingSpace !== null && $candidate !== []) {
                    $candidate = $this->append($candidate, $pendingSpace);
                }
                $candidate[] = $piece;
                if ($this->width($candidate) > $width + 0.001) {
                    if ($line === [] || in_array($style->whiteSpace, ['pre', 'nowrap'], true)) {
                        throw new UnsupportedFeature('An inline image exceeds the available line width. Supply smaller explicit dimensions.');
                    }
                    $lines[] = $this->line($line, $x, $width, $style);
                    $candidate = [$piece];
                }
                if ($piece->imageWidth > $width + 0.001) {
                    throw new UnsupportedFeature('An image exceeds the available content width.');
                }
                $line = $candidate;
                $pendingSpace = null;
                $explicitBreak = false;
                continue;
            }
            $preserve = in_array($piece->style->whiteSpace, ['pre', 'pre-wrap'], true);
            $tokens = preg_split('/([\x20\t\r\n\f]+)/u', $piece->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            if ($tokens === false) {
                throw new \InvalidArgumentException('Inline text must be valid UTF-8.');
            }
            foreach ($tokens as $token) {
                $isSpace = preg_match('/^[\x20\t\r\n\f]+$/D', $token) === 1;
                if ($isSpace && !$preserve) {
                    $pendingSpace = new InlinePiece(' ', $piece->style, $piece->semanticId, $piece->uri);
                    continue;
                }
                $parts = $preserve && $isSpace ? preg_split($piece->style->whiteSpace === 'pre-wrap' ? '/(\r\n|\r|\n|\f|\t| )/' : '/(\r\n|\r|\n|\f|\t)/', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) : [$token];
                foreach ($parts ?: [] as $part) {
                    if ($preserve && in_array($part, ["\r\n", "\r", "\n", "\f"], true)) {
                        $lines[] = $this->line($line, $x, $width, $style);
                        $line = [];
                        $pendingSpace = null;
                        $explicitBreak = true;
                        continue;
                    }
                    if ($part === "\t") {
                        $space = $this->measure($this->face($piece->style), ' ', $piece->style->fontSize);
                        $column = (int) floor($this->width($line) / max($space, 0.0001));
                        $part = str_repeat(' ', 8 - $column % 8);
                    }
                    $next = new InlinePiece($part, $piece->style, $piece->semanticId, $piece->uri);
                    $candidate = $line;
                    if ($pendingSpace !== null && $candidate !== []) {
                        $candidate = $this->append($candidate, $pendingSpace);
                    }
                    $candidate = $this->append($candidate, $next);
                    if ($this->width($candidate) > $width + 0.001) {
                        if ($line === [] || in_array($piece->style->whiteSpace, ['pre', 'nowrap'], true)) {
                            throw new UnsupportedFeature('An unbreakable text run exceeds the content width; automatic word splitting is outside this layout profile.');
                        }
                        $lines[] = $this->line($line, $x, $width, $style);
                        $candidate = [$next];
                        if ($this->width($candidate) > $width + 0.001) {
                            throw new UnsupportedFeature('An unbreakable text run exceeds the content width.');
                        }
                    }
                    $line = $candidate;
                    $pendingSpace = null;
                    $explicitBreak = false;
                }
            }
        }
        if ($line !== [] || $explicitBreak) {
            $lines[] = $this->line($line, $x, $width, $style);
        }
        foreach ($lines as $item) {
            $item->paragraph = $paragraph;
            $item->orphans = $style->orphans;
            $item->widows = $style->widows;
        }

        return $lines;
    }

    public function face(ComputedStyle $style): FontFace
    {
        return $this->options->fonts->face(match (true) {
            $style->fontWeight >= 600 && $style->italic => FontStyle::BoldItalic,
            $style->fontWeight >= 600 => FontStyle::Bold,
            $style->italic => FontStyle::Italic,
            default => FontStyle::Regular,
        });
    }

    /** @param list<InlinePiece> $pieces
     * @return list<InlinePiece>
     */
    private function append(array $pieces, InlinePiece $piece): array
    {
        $last = $pieces === [] ? null : $pieces[array_key_last($pieces)];
        if ($last !== null && $last->image === null && $last->style == $piece->style && $last->semanticId === $piece->semanticId && $last->uri === $piece->uri) {
            $pieces[array_key_last($pieces)] = new InlinePiece($last->text . $piece->text, $piece->style, $piece->semanticId, $piece->uri);
        } else {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /** @param list<InlinePiece> $pieces */
    private function width(array $pieces): float
    {
        $width = 0.0;
        foreach ($pieces as $piece) {
            $width += $piece->image !== null ? $piece->imageWidth : $this->measure($this->face($piece->style), $piece->text, $piece->style->fontSize);
        }

        return $width;
    }

    private function measure(FontFace $font, string $text, float $size): float
    {
        $this->measuredBytes += strlen($text);
        if ($this->measuredBytes > $this->options->limits->maxTextCharacters * 256) {
            throw new LimitExceeded('Cumulative text shaping work budget exhausted.');
        }

        $key = spl_object_id($font) . ':' . $size . ':' . $text;
        if (isset($this->measurements[$key])) {
            return $this->measurements[$key];
        }
        $width = $font->measure($text, $size);
        $entryBytes = strlen($key) + 64;
        if (count($this->measurements) < $this->options->limits->maxCommands
            && $this->cachedBytes + $entryBytes <= min(4_194_304, $this->options->limits->maxTextCharacters * 16)) {
            $this->measurements[$key] = $width;
            $this->cachedBytes += $entryBytes;
        }

        return $width;
    }

    /** @param list<InlinePiece> $pieces */
    private function line(array $pieces, float $x, float $width, ComputedStyle $style): FlowItem
    {
        if (++$this->lineCount > $this->options->limits->maxCommands) {
            throw new LimitExceeded('Inline line budget exhausted.');
        }
        $metrics = $this->face($style)->metrics($style->fontSize);
        $ascent = $metrics->ascent;
        $descent = $metrics->descent;
        $height = max($style->lineHeight, $ascent + $descent);
        foreach ($pieces as $piece) {
            if ($piece->image !== null) {
                $ascent = max($ascent, $piece->imageHeight);
            } else {
                $metrics = $this->face($piece->style)->metrics($piece->style->fontSize);
                $ascent = max($ascent, $metrics->ascent);
                $descent = max($descent, $metrics->descent);
            }
            $height = max($height, $piece->style->lineHeight);
        }
        $height = max($height, $ascent + $descent);
        $baseline = ($height - $ascent - $descent) / 2 + $ascent;
        $used = $this->width($pieces);
        $x += match ($style->textAlign) {
            'center' => ($width - $used) / 2,
            'right' => $width - $used,
            default => 0,
        };
        $commands = [];
        foreach ($pieces as $piece) {
            if ($piece->image !== null) {
                $commands[] = new Image($x, $baseline - $piece->imageHeight, $piece->imageWidth, $piece->imageHeight, $piece->image, $piece->semanticId);
                $x += $piece->imageWidth;
                continue;
            }
            $font = $this->face($piece->style);
            $runWidth = $this->measure($font, $piece->text, $piece->style->fontSize);
            if ($piece->style->background !== null) {
                $commands[] = new Rectangle($x, 0, $runWidth, $height, $piece->style->background);
            }
            $commands[] = new Text($font, $piece->text, $piece->style->fontSize, $x, $baseline, $piece->style->color, $piece->semanticId ?? throw new \LogicException('Text needs structure.'), $piece->uri);
            if ($piece->style->underline && $runWidth > 0) {
                $commands[] = new Rectangle($x, min($height - 0.5, $baseline + $piece->style->fontSize * 0.1), $runWidth, max(0.5, $piece->style->fontSize / 16), $piece->style->color);
            }
            $x += $runWidth;
        }

        $this->commandCount += count($commands);
        if ($this->commandCount > $this->options->limits->maxCommands) {
            throw new LimitExceeded('Inline drawing command budget exhausted.');
        }

        return new FlowItem($height, $commands);
    }
}
