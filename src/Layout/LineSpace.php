<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

/** Available horizontal interval at a particular vertical position. */
final readonly class LineSpace
{
    public function __construct(public float $x, public float $width, public float $top) {}
}
