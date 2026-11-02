<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Drawing;

use PDF\HtmlToPdf\Style\Color;

final readonly class Rectangle implements Command
{
    public function __construct(public float $x, public float $y, public float $width, public float $height, public ?Color $color = null, public ?Color $borderColor = null, public float $borderWidth = 0, public ?string $semanticId = null) {}
}
