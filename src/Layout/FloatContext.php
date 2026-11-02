<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;

/** Document-local exclusions; a floated box receives a fresh nested context. */
final class FloatContext
{
    /** @var list<FloatPlacement> */
    private array $placements = [];
    private float $lastTop = 0;
    private readonly FloatWorkBudget $budget;

    public function __construct(int|FloatWorkBudget $maximumWork)
    {
        $this->budget = $maximumWork instanceof FloatWorkBudget ? $maximumWork : new FloatWorkBudget($maximumWork);
    }

    public function nested(): self
    {
        return new self($this->budget);
    }

    public function checkpoint(): int
    {
        return count($this->placements);
    }

    /** Test only floats that existed before a normal block began its layout. */
    public function overlapsBefore(int $checkpoint, float $x, float $top, float $width, float $height): bool
    {
        if ($checkpoint < 0 || $checkpoint > count($this->placements) || !is_finite($x) || !is_finite($top)
            || !is_finite($width) || !is_finite($height) || $width < 0 || $height < 0) {
            throw new \InvalidArgumentException('A float overlap query requires valid bounds and a context checkpoint.');
        }
        if ($width === 0.0 || $height === 0.0) {
            return false;
        }
        for ($index = 0; $index < $checkpoint; ++$index) {
            $this->account();
            $placement = $this->placements[$index];
            if ($placement->x < $x + $width && $placement->x + $placement->width > $x
                && $placement->top < $top + $height && $placement->bottom() > $top) {
                return true;
            }
        }

        return false;
    }

    public function place(string $side, float $width, float $height, float $top, float $left, float $available): FloatPlacement
    {
        if (!in_array($side, ['left', 'right'], true) || !is_finite($width) || !is_finite($height) || $width <= 0 || $height < 0) {
            throw new UnsupportedFeature('Floats require a valid side and finite non-negative dimensions.');
        }
        $space = $this->space($left, $available, max($top, $this->lastTop), max($height, 0.001), $width);
        $x = $side === 'left' ? $space->x : $space->x + $space->width - $width;
        $placement = new FloatPlacement($side, $x, $space->top, $width, $height);
        $this->placements[] = $placement;
        $this->lastTop = $space->top;
        $this->account();

        return $placement;
    }

    public function clear(string $side, float $top): float
    {
        if ($side === 'none') {
            return $top;
        }
        foreach ($this->placements as $placement) {
            $this->account();
            if ($side === 'both' || $side === $placement->side) {
                $top = max($top, $placement->bottom());
            }
        }

        return $top;
    }

    public function space(float $x, float $width, float $top, float $height, float $minimumWidth = 0): LineSpace
    {
        if (!is_finite($x) || !is_finite($width) || !is_finite($top) || !is_finite($height) || !is_finite($minimumWidth)
            || $width <= 0 || $height < 0 || $minimumWidth < 0 || $minimumWidth > $width + 0.001) {
            throw new UnsupportedFeature('A float or inline item exceeds its containing width.');
        }
        do {
            $this->account();
            $left = $x;
            $right = $x + $width;
            $next = null;
            foreach ($this->placements as $placement) {
                $this->account();
                if ($placement->bottom() <= $top + 0.000001 || $placement->top >= $top + max($height, 0.000001)
                    || $placement->x + $placement->width <= $x || $placement->x >= $x + $width) {
                    continue;
                }
                if ($placement->side === 'left') {
                    $left = max($left, $placement->x + $placement->width);
                } else {
                    $right = min($right, $placement->x);
                }
                $next = $next === null ? $placement->bottom() : min($next, $placement->bottom());
            }
            $available = max(0.0, $right - $left);
            if ($available > 0 && $available + 0.001 >= $minimumWidth) {
                return new LineSpace($left, $available, $top);
            }
            if ($next === null || $next <= $top) {
                throw new UnsupportedFeature('No horizontal space remains in the float context.');
            }
            $top = $next;
        } while (true);
    }

    private function account(): void
    {
        $this->budget->account();
    }
}
