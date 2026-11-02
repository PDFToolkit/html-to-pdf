<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Drawing\Command;

/** One indivisible vertical slice, in document coordinates relative to its top. */
final class FlowItem
{
    public bool $breakBefore = false;
    public bool $breakAfter = false;
    /** @var list<string> */
    public array $avoidGroups = [];
    public ?string $paragraph = null;
    public int $orphans = 2;
    public int $widows = 2;
    /** @var list<FlowItem> */
    public array $headers = [];
    public ?string $table = null;
    public bool $margin = false;
    public bool $marginBoundary = false;
    /** Float painting does not separate adjoining in-flow margins. */
    public bool $outOfFlow = false;
    /** Visible overflow does not increase a CSS box's normal-flow height. */
    public ?float $paintHeight = null;

    /** @param list<Command> $commands */
    public function __construct(public float $height, public array $commands = [])
    {
        if (!is_finite($height) || $height < 0) {
            throw new \InvalidArgumentException('Flow heights must be finite and non-negative.');
        }
    }

    public function extent(): float
    {
        return max($this->height, $this->paintHeight ?? 0.0);
    }
}
