<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Graphics\GraphicsState\LineCapStyle;
use PDF\Graphics\GraphicsState\LineDashPattern;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Style\BorderSide;
use PDF\HtmlToPdf\Style\BorderStyle;
use PDF\HtmlToPdf\Style\Color;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\ClipPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\CloseSubpath;
use PDF\Syntax\ContentStreamsResources\Operators\EndPath;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\LineTo;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTo;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineCapStyle;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineDashPattern;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\StrokePath;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Constant operator work per side, independent of border length or dash count. */
final readonly class BorderPainter
{
    /** Estimate actual emitted dash segments, cumulatively checked by Renderer. */
    public function patternWork(BorderBox $box): float
    {
        $work = 0.0;
        foreach (['top', 'right', 'bottom', 'left'] as $name) {
            $side = $box->borders->{$name};
            $segment = $this->segment($box, $name, $side);
            if ($segment === null) {
                continue;
            }
            $period = ($side->style === BorderStyle::Dotted ? 2 : 6) * $side->effectiveWidth();
            $work += ceil($segment['length'] / $period) + 1;
        }
        return $work;
    }

    /** @return list<Operator> */
    public function operators(BorderBox $box, float $pageHeight): array
    {
        if ($box->height === 0.0 || !$box->borders->hasWidth()) {
            return [];
        }
        $edges = $box->borders->widths();
        $x = $box->x;
        $y = $box->y - $box->offset;
        $right = $x + $box->width;
        $bottom = $y + $box->totalHeight;
        $outer = [[$x, $y], [$right, $y], [$right, $bottom], [$x, $bottom]];
        $inner = [[$x + $edges->left, $y + $edges->top], [$right - $edges->right, $y + $edges->top], [$right - $edges->right, $bottom - $edges->bottom], [$x + $edges->left, $bottom - $edges->bottom]];
        $result = [
            new SaveGraphicsState(),
            new AppendRectangle(new NumericType($x), new NumericType($pageHeight - $box->y - $box->height), new NumericType($box->width), new NumericType($box->height)),
            new ClipPathNonzero(), new EndPath(),
        ];
        $fills = [];
        foreach (['top', 'right', 'bottom', 'left'] as $index => $name) {
            $side = $box->borders->{$name};
            if ($side->effectiveWidth() === 0.0 || $side->color === null) {
                continue;
            }
            $next = ($index + 1) % 4;
            $quad = [$outer[$index], $outer[$next], $inner[$next], $inner[$index]];
            if (in_array($side->style, [BorderStyle::Dashed, BorderStyle::Dotted], true)) {
                $segment = $this->segment($box, $name, $side);
                if ($segment !== null) {
                    array_push($result, ...$this->pattern($quad, $segment['line'], $side, $pageHeight, $segment['phase']));
                }
            } else {
                $bands = match ($side->style) {
                    BorderStyle::Double => [[0.0, 1 / 3, $side->color], [2 / 3, 1.0, $side->color]],
                    BorderStyle::Groove, BorderStyle::Ridge => [[0.0, 0.5, $this->shade($side->color, ($index === 0 || $index === 3) === ($side->style === BorderStyle::Ridge))], [0.5, 1.0, $this->shade($side->color, ($index === 0 || $index === 3) !== ($side->style === BorderStyle::Ridge))]],
                    BorderStyle::Inset, BorderStyle::Outset => [[0.0, 1.0, $this->shade($side->color, ($index === 0 || $index === 3) === ($side->style === BorderStyle::Outset))]],
                    default => [[0.0, 1.0, $side->color]],
                };
                foreach ($bands as [$start, $end, $color]) {
                    $key = json_encode([$color->red, $color->green, $color->blue], JSON_THROW_ON_ERROR);
                    $fills[$key] ??= ['color' => $color, 'operators' => []];
                    array_push($fills[$key]['operators'], ...$this->path($this->band($quad, $start, $end), $pageHeight));
                }
            }
        }
        // A shared fill removes antialiasing seams between adjacent same-color sides.
        foreach ($fills as $fill) {
            $color = $fill['color'];
            $result = [...$result, new SetNonstrokingRGBColor(new NumericType($color->red), new NumericType($color->green), new NumericType($color->blue)), ...$fill['operators'], new FillPathNonzero()];
        }
        $result[] = new RestoreGraphicsState();
        return $result;
    }

    /**
     * Clip work along the path before asking a PDF viewer to dash it. Round dots
     * whose centres lie just outside a slice must still contribute their caps.
     *
     * @return array{line:array{array{float,float},array{float,float}},length:float,phase:float}|null
     */
    private function segment(BorderBox $box, string $name, BorderSide $side): ?array
    {
        $width = $side->effectiveWidth();
        if ($box->height === 0.0 || $side->color === null || $width === 0.0 || !in_array($side->style, [BorderStyle::Dotted, BorderStyle::Dashed], true)) {
            return null;
        }
        $top = $box->y - $box->offset;
        if ($name === 'top' || $name === 'bottom') {
            if (($name === 'top' && $box->offset >= $width)
                || ($name === 'bottom' && $box->offset + $box->height <= $box->totalHeight - $width)) {
                return null;
            }
            $y = $name === 'top' ? $top + $width / 2 : $top + $box->totalHeight - $width / 2;
            return ['line' => [[$box->x, $y], [$box->x + $box->width, $y]], 'length' => $box->width, 'phase' => 0.0];
        }
        $radius = $side->style === BorderStyle::Dotted ? $width / 2 : 0.0;
        $start = max(0.0, $box->offset - $radius);
        $end = min($box->totalHeight, $box->offset + $box->height + $radius);
        if ($start >= $end) {
            return null;
        }
        $x = $name === 'left' ? $box->x + $width / 2 : $box->x + $box->width - $width / 2;
        $period = ($side->style === BorderStyle::Dotted ? 2 : 6) * $width;
        return ['line' => [[$x, $top + $start], [$x, $top + $end]], 'length' => $end - $start, 'phase' => fmod($start, $period)];
    }

    /** @param list<array{float,float}> $quad
     * @param list<array{float,float}> $line
     * @return list<Operator>
     */
    private function pattern(array $quad, array $line, BorderSide $side, float $height, float $phase): array
    {
        $color = $side->color ?? throw new \LogicException('Transparent borders do not paint.');
        $dotted = $side->style === BorderStyle::Dotted;
        $width = $side->effectiveWidth();
        return [
            new SaveGraphicsState(), ...$this->path($quad, $height), new ClipPathNonzero(), new EndPath(),
            new SetStrokingRGBColor(new NumericType($color->red), new NumericType($color->green), new NumericType($color->blue)),
            new SetLineWidth(new NumericType($width)),
            new SetLineCapStyle($dotted ? LineCapStyle::Round : LineCapStyle::Butt),
            new SetLineDashPattern(new LineDashPattern(new ArrayType(new NumericType($dotted ? 0 : 3 * $width), new NumericType($dotted ? 2 * $width : 3 * $width)), new NumericType($phase))),
            new MoveTo(new NumericType($line[0][0]), new NumericType($height - $line[0][1])),
            new LineTo(new NumericType($line[1][0]), new NumericType($height - $line[1][1])),
            new StrokePath(), new RestoreGraphicsState(),
        ];
    }

    /** @param list<array{float,float}> $points
     * @return list<Operator>
     */
    private function path(array $points, float $height): array
    {
        $operators = [];
        foreach ($points as $index => [$x, $y]) {
            $operators[] = $index === 0 ? new MoveTo(new NumericType($x), new NumericType($height - $y)) : new LineTo(new NumericType($x), new NumericType($height - $y));
        }
        $operators[] = new CloseSubpath();
        return $operators;
    }

    /** @param list<array{float,float}> $quad
     * @return list<array{float,float}>
     */
    private function band(array $quad, float $start, float $end): array
    {
        return [$this->between($quad[0], $quad[3], $start), $this->between($quad[1], $quad[2], $start), $this->between($quad[1], $quad[2], $end), $this->between($quad[0], $quad[3], $end)];
    }

    /** @param array{float,float} $from
     * @param array{float,float} $to
     * @return array{float,float}
     */
    private function between(array $from, array $to, float $fraction): array
    {
        return [$from[0] + ($to[0] - $from[0]) * $fraction, $from[1] + ($to[1] - $from[1]) * $fraction];
    }

    private function shade(Color $color, bool $light): Color
    {
        return $light ? new Color(($color->red + 1) / 2, ($color->green + 1) / 2, ($color->blue + 1) / 2) : new Color($color->red / 2, $color->green / 2, $color->blue / 2);
    }
}
