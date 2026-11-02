<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Drawing\RadioButton;
use PDF\HtmlToPdf\Style\ComputedStyle;

final readonly class InlinePiece
{
    public function __construct(
        public string $text,
        public ComputedStyle $style,
        public ?string $semanticId,
        public ?string $uri = null,
        public bool $lineBreak = false,
        public ?ImageAsset $image = null,
        public float $imageWidth = 0,
        public float $imageHeight = 0,
        public ?RadioButton $radio = null,
    ) {}
}
