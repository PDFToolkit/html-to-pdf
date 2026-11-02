<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Drawing\Command;

final readonly class LayoutPage
{
    /** @param list<Command> $commands */
    public function __construct(public float $width, public float $height, public array $commands) {}
}
