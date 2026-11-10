<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FaceChain;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontMatcher;
use PDF\HtmlToPdf\Font\TextItemizer;
use PDF\HtmlToPdf\Font\TextSegment;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\FontSlant;
use PDF\HtmlToPdf\Style\TextSpacing;

/** Horizontal inline flow, measured with exactly the font shaper used by serialization. */
final class InlineFormatter
{
    private int $measuredBytes = 0;
    /** @var array<string, float> */
    private array $measurements = [];
    private int $cachedBytes = 0;
    private int $lineCount = 0;
    private int $commandCount = 0;
    private readonly FontMatcher $matcher;
    private readonly TextItemizer $itemizer;
    /** @var array<string, list<TextSegment>> */
    private array $itemizations = [];
    /** @var \WeakMap<ComputedStyle, FaceChain> */
    private \WeakMap $chains;
    /** @var array<string, FaceChain> */
    private array $chainsByKey = [];
    /** @var array<string, true> */
    private array $notes = [];
    /** @var array<string, int> */
    private array $noteCounts = [];

    private const int MAX_NOTES_PER_CODE = 8;

    public function __construct(private readonly ConversionOptions $options, private readonly ?Diagnostics $diagnostics = null)
    {
        $this->matcher = new FontMatcher();
        $this->itemizer = new TextItemizer();
        $this->chains = new \WeakMap();
    }

    /** Advance of one styled text, summed over the faces font matching assigns to its characters. */
    public function advance(string $text, ComputedStyle $style): float
    {
        $width = 0.0;
        foreach ($this->segments($text, $style) as $segment) {
            $width += $this->measure($segment->face->face, $segment->text, $style->fontSize, $style->textSpacing);
        }

        return $width;
    }

    /**
     * One text command per face, positioned with the advances that line breaking measured.
     *
     * @return array{list<Text>, float} The commands and their total advance.
     */
    public function texts(string $text, ComputedStyle $style, float $x, float $baseline, ?string $semanticId, ?string $uri = null): array
    {
        $commands = [];
        $start = $x;
        foreach ($this->segments($text, $style) as $segment) {
            $commands[] = new Text($segment->face->face, $segment->text, $style->fontSize, $x, $baseline, $style->color, $semanticId, $uri, $style->textSpacing);
            $x += $this->measure($segment->face->face, $segment->text, $style->fontSize, $style->textSpacing);
        }

        return [$commands, $x - $start];
    }

    /**
     * Character fallback across the face chain. A single candidate needs no coverage probe,
     * so text in one supplied face keeps the single run it always had.
     *
     * @return list<TextSegment>
     */
    private function segments(string $text, ComputedStyle $style): array
    {
        $chain = $this->chain($style);
        if (count($chain->faces) === 1) {
            return [new TextSegment($chain->primary(), $text)];
        }
        $this->measuredBytes += strlen($text);
        if ($this->measuredBytes > $this->options->limits->maxTextCharacters * 256) {
            throw new LimitExceeded('Cumulative text shaping work budget exhausted.');
        }
        $key = spl_object_id($chain) . ':' . $text;
        $segments = $this->itemizations[$key] ?? null;
        if ($segments === null) {
            $segments = $this->itemizer->itemize($text, $chain);
            $entryBytes = strlen($key) + 64 * count($segments);
            if (count($this->itemizations) < $this->options->limits->maxCommands
                && $this->cachedBytes + $entryBytes <= min(4_194_304, $this->options->limits->maxTextCharacters * 16)) {
                $this->itemizations[$key] = $segments;
                $this->cachedBytes += $entryBytes;
            }
        }
        foreach ($segments as $segment) {
            if ($segment->split) {
                if (($style->textSpacing->letterSpacing ?? 0.0) !== 0.0) {
                    throw new UnsupportedFeature('Letter spacing across a grapheme divided between fonts requires shared cluster shaping.');
                }
                $this->note('font.cluster-split', 'No single face of ' . ($style->fontFamily->names === [] ? 'the default family' : $style->fontFamily->css()) . ' has every character of a grapheme cluster; its characters are drawn with different fonts.');
            }
        }

        return $segments;
    }

    /** @param list<InlinePiece> $pieces
     * @return list<FlowItem>
     */
    public function format(array $pieces, float $x, float $width, ComputedStyle $style, string $paragraph, ?LineExclusions $exclusions = null, float $top = 0, bool $firstLine = true, ?float $indentBasis = null): array
    {
        /** @var list<InlinePiece> $line */
        $line = [];
        $lines = [];
        $pendingSpace = null;
        $breakLine = [];
        $word = [];
        $preservedSpace = false;
        $explicitBreak = false;
        $cursor = 0.0;
        $lineOffset = 0.0;
        $indent = $firstLine ? $style->textIndent->resolve($indentBasis ?? $width) : 0.0;
        foreach ($pieces as $piece) {
            if ($piece->lineBreak) {
                $lines[] = $this->emit($line, $x, $width, $style, $exclusions, $top, $cursor, $indent, $lineOffset);
                $line = [];
                $breakLine = $word = [];
                $preservedSpace = false;
                $pendingSpace = null;
                $explicitBreak = true;
                continue;
            }
            if ($piece->image !== null || $piece->radio !== null) {
                $candidate = $line;
                if ($pendingSpace !== null && $candidate !== []) {
                    $candidate = $this->append($candidate, $pendingSpace);
                }
                $candidate[] = $piece;
                if ($this->width($candidate) > $this->available($candidate, $line === [], $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent)->width + 0.001) {
                    if ($line === [] || in_array($style->whiteSpace, ['pre', 'nowrap'], true)) {
                        throw new UnsupportedFeature('An inline image exceeds the available line width. Supply smaller explicit dimensions.');
                    }
                    $lines[] = $this->emit($line, $x, $width, $style, $exclusions, $top, $cursor, $indent, $lineOffset);
                    $candidate = [$piece];
                }
                if (($piece->radio->width ?? $piece->imageWidth) > $this->available($candidate, true, $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent)->width + 0.001) {
                    throw new UnsupportedFeature('An image exceeds the available content width.');
                }
                $space = $this->available($candidate, $line === [], $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent);
                $lineOffset = $space->top - $top - $cursor;
                $line = $candidate;
                $breakLine = $line;
                $word = [];
                $preservedSpace = true;
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
                    $pendingSpace ??= new InlinePiece(' ', $piece->style, $piece->semanticId, $piece->uri);
                    continue;
                }
                $parts = $preserve && $isSpace ? preg_split($piece->style->whiteSpace === 'pre-wrap' ? '/(\r\n|\r|\n|\f|\t| )/' : '/(\r\n|\r|\n|\f|\t)/', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) : [$token];
                foreach ($parts ?: [] as $part) {
                    if ($preserve && in_array($part, ["\r\n", "\r", "\n", "\f"], true)) {
                        $lines[] = $this->emit($line, $x, $width, $style, $exclusions, $top, $cursor, $indent, $lineOffset);
                        $line = [];
                        $breakLine = $word = [];
                        $preservedSpace = false;
                        $pendingSpace = null;
                        $explicitBreak = true;
                        continue;
                    }
                    if ($part === "\t") {
                        if (($piece->style->textSpacing->letterSpacing ?? 0.0) !== 0.0 || ($piece->style->textSpacing->wordSpacing ?? 0.0) !== 0.0) {
                            throw new UnsupportedFeature('Preserved tabs with nonzero text spacing require exact tab-stop positioning.');
                        }
                        $space = $this->measure($this->face($piece->style), ' ', $piece->style->fontSize, $piece->style->textSpacing);
                        $column = (int) floor($this->width($line) / max($space, 0.0001));
                        $part = str_repeat(' ', 8 - $column % 8);
                    }
                    $next = new InlinePiece($part, $piece->style, $piece->semanticId, $piece->uri);
                    if ($pendingSpace !== null || ($preserve && $isSpace) || $preservedSpace) {
                        $breakLine = $line;
                        $word = [$next];
                    } else {
                        $word = $this->append($word, $next);
                    }
                    $preservedSpace = $preserve && $isSpace;
                    $candidate = $line;
                    if ($pendingSpace !== null && $candidate !== []) {
                        $candidate = $this->append($candidate, $pendingSpace);
                    }
                    $candidate = $this->append($candidate, $next);
                    if ($this->width($candidate) > $this->available($candidate, $breakLine === [], $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent)->width + 0.001) {
                        if ($breakLine === [] || in_array($piece->style->whiteSpace, ['pre', 'nowrap'], true)) {
                            throw new UnsupportedFeature('An unbreakable text run exceeds the content width; automatic word splitting is outside this layout profile.');
                        }
                        $lines[] = $this->emit($breakLine, $x, $width, $style, $exclusions, $top, $cursor, $indent, $lineOffset);
                        $candidate = $word;
                        $breakLine = [];
                        if ($this->width($candidate) > $this->available($candidate, true, $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent)->width + 0.001) {
                            throw new UnsupportedFeature('An unbreakable text run exceeds the content width.');
                        }
                    }
                    $space = $this->available($candidate, $breakLine === [], $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent);
                    $lineOffset = $space->top - $top - $cursor;
                    $line = $candidate;
                    $pendingSpace = null;
                    $explicitBreak = false;
                }
            }
        }
        if ($line !== [] || $explicitBreak) {
            $lines[] = $this->emit($line, $x, $width, $style, $exclusions, $top, $cursor, $indent, $lineOffset);
        }
        foreach ($lines as $item) {
            $item->paragraph = $paragraph;
            $item->orphans = $style->orphans;
            $item->widows = $style->widows;
        }

        return $lines;
    }

    /** The first face CSS font matching selects for a computed style. */
    public function face(ComputedStyle $style): FontFace
    {
        return $this->chain($style)->primary()->face;
    }

    private function chain(ComputedStyle $style): FaceChain
    {
        if (isset($this->chains[$style])) {
            return $this->chains[$style];
        }
        $slant = $style->fontSlant();
        $key = $style->fontFamily->css() . '|' . $slant->value . '|' . $style->fontWeight;
        if (!isset($this->chainsByKey[$key])) {
            $this->chainsByKey[$key] = $this->matcher->chain($this->options->fonts, $style->fontFamily, $slant, $style->fontWeight);
            $this->explain($this->chainsByKey[$key], $style, $slant);
        }

        return $this->chains[$style] = $this->chainsByKey[$key];
    }

    /** Matching outcomes that CSS defines but that a caller may not expect are recorded, never rejected. */
    private function explain(FaceChain $chain, ComputedStyle $style, FontSlant $slant): void
    {
        $face = $chain->primary();
        if (!$chain->listedFamilyMatched && $style->fontFamily->names !== []) {
            $this->note('font.default-family', 'No supplied family matches ' . $style->fontFamily->css() . ' in ' . $slant->value . ' style; the default family is used.');
        }
        if (!$chain->listedFamilyMatched && $chain->styleRelaxed) {
            $this->note('font.style-fallback', 'The default family has no ' . $slant->value . ' face; its ' . $face->slant->value . ' face is used.');
        }
        if (($style->fontWeight >= 600) !== ($face->weight >= 600)) {
            $this->note('font.weight-fallback', sprintf('Weight %d has no face on its side of the bold threshold in the matched family; its %d face is used.', $style->fontWeight, $face->weight));
        }
    }

    private function note(string $code, string $message): void
    {
        if ($this->diagnostics === null || isset($this->notes[$code . ':' . $message])) {
            return;
        }
        $this->notes[$code . ':' . $message] = true;
        $count = $this->noteCounts[$code] = ($this->noteCounts[$code] ?? 0) + 1;
        if ($count <= self::MAX_NOTES_PER_CODE) {
            $this->diagnostics->inform($code, $message);
        } elseif ($count === self::MAX_NOTES_PER_CODE + 1) {
            $this->diagnostics->inform($code, 'Further ' . $code . ' notes are omitted.');
        }
    }

    /** @param list<InlinePiece> $pieces
     * @return list<InlinePiece>
     */
    private function append(array $pieces, InlinePiece $piece): array
    {
        $last = $pieces === [] ? null : $pieces[array_key_last($pieces)];
        if ($last !== null && $last->image === null && $last->radio === null && $piece->image === null && $piece->radio === null && $last->style == $piece->style && $last->semanticId === $piece->semanticId && $last->uri === $piece->uri) {
            $pieces[array_key_last($pieces)] = new InlinePiece($last->text . $piece->text, $piece->style, $piece->semanticId, $piece->uri);
        } else {
            if ($last !== null && $last->image === null && $last->radio === null && $piece->image === null && $piece->radio === null
                && (($last->style->textSpacing->letterSpacing ?? 0.0) !== 0.0 || ($piece->style->textSpacing->letterSpacing ?? 0.0) !== 0.0)
                && preg_match('/\X\z/u', $last->text, $left) === 1 && preg_match('/^\X/u', $piece->text, $right) === 1
                && preg_match('/^\X/u', $left[0] . $right[0], $joined) === 1 && strlen($joined[0]) !== strlen($left[0])) {
                throw new UnsupportedFeature('Letter spacing across a grapheme split between inline runs requires shared cluster shaping.');
            }
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /** @param list<InlinePiece> $pieces */
    private function width(array $pieces): float
    {
        $width = 0.0;
        foreach ($pieces as $piece) {
            $width += $piece->radio->width ?? ($piece->image !== null ? $piece->imageWidth : $this->advance($piece->text, $piece->style));
        }

        return $width;
    }

    private function measure(FontFace $font, string $text, float $size, TextSpacing $spacing): float
    {
        $this->measuredBytes += strlen($text);
        if ($this->measuredBytes > $this->options->limits->maxTextCharacters * 256) {
            throw new LimitExceeded('Cumulative text shaping work budget exhausted.');
        }

        $key = spl_object_id($font) . ':' . $size . ':' . $spacing->cacheKey() . ':' . $text;
        if (isset($this->measurements[$key])) {
            return $this->measurements[$key];
        }
        $width = $font->measure($text, $size, $spacing);
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
        [$height, $baseline] = $this->metrics($pieces, $style);
        $used = $this->width($pieces);
        $x += match ($style->textAlign) {
            'center' => ($width - $used) / 2,
            'right' => $width - $used,
            default => 0,
        };
        $commands = [];
        foreach ($pieces as $piece) {
            if ($piece->radio !== null) {
                $radio = $piece->radio;
                $commands[] = new RadioButton($x, $baseline - $radio->height, $radio->width, $radio->height, $radio->checked, $radio->disabled, $radio->semanticId);
                $x += $radio->width;
                continue;
            }
            if ($piece->image !== null) {
                $commands[] = new Image($x, $baseline - $piece->imageHeight, $piece->imageWidth, $piece->imageHeight, $piece->image, $piece->semanticId);
                $x += $piece->imageWidth;
                continue;
            }
            [$texts, $runWidth] = $this->texts($piece->text, $piece->style, $x, $baseline, $piece->semanticId ?? throw new \LogicException('Text needs structure.'), $piece->uri);
            if ($piece->style->background !== null) {
                $commands[] = new Rectangle($x, 0, $runWidth, $height, $piece->style->background);
            }
            array_push($commands, ...$texts);
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

    /** @param list<InlinePiece> $pieces
     * @return array{float,float} CSS line box height and baseline, including negative half-leading.
     */
    private function metrics(array $pieces, ComputedStyle $style): array
    {
        $metrics = $this->face($style)->metrics($style->fontSize);
        $leading = ($style->lineHeight - $metrics->ascent - $metrics->descent) / 2;
        $above = $metrics->ascent + $leading;
        $below = $metrics->descent + $leading;
        foreach ($pieces as $piece) {
            if ($piece->image !== null || $piece->radio !== null) {
                $above = max($above, $piece->radio->height ?? $piece->imageHeight);
                $below = max($below, 0.0);
            } else {
                $metrics = $this->face($piece->style)->metrics($piece->style->fontSize);
                $leading = ($piece->style->lineHeight - $metrics->ascent - $metrics->descent) / 2;
                $above = max($above, $metrics->ascent + $leading);
                $below = max($below, $metrics->descent + $leading);
            }
        }
        return [$above + $below, $above];
    }

    /** @param list<InlinePiece> $pieces */
    private function available(array $pieces, bool $empty, float $x, float $width, ComputedStyle $style, ?LineExclusions $exclusions, float $top, float $indent): LineSpace
    {
        $space = $exclusions === null ? new LineSpace($x, $width, $top)
            : $exclusions->space($top, $this->metrics($pieces, $style)[0], $empty ? max(0.0, $this->width($pieces) + $indent) : 0);
        if (!is_finite($space->x + $indent) || !is_finite($space->width - $indent) || $space->width - $indent <= 0) {
            throw new UnsupportedFeature('First-line indentation leaves no finite positive line width.');
        }
        return new LineSpace($space->x + $indent, $space->width - $indent, $space->top);
    }

    /** @param list<InlinePiece> $pieces */
    private function emit(array $pieces, float $x, float $width, ComputedStyle $style, ?LineExclusions $exclusions, float $top, float &$cursor, float &$indent, float &$lineOffset): FlowItem
    {
        $space = $this->available($pieces, true, $x, $width, $style, $exclusions, $top + $cursor + $lineOffset, $indent);
        $item = $this->line($pieces, $space->x, $space->width, $style);
        $gap = $space->top - $top - $cursor;
        if ($gap > 0) {
            $translation = new CommandTranslation();
            $item->commands = array_map(static fn($command) => $translation->move($command, 0, $gap), $item->commands);
            $item->height += $gap;
        }
        $cursor += $item->height;
        $indent = 0.0;
        $lineOffset = 0.0;
        return $item;
    }

}
