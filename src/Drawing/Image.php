<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Drawing;

use PDF\HtmlToPdf\Resource\ImageAsset;

final readonly class Image implements Command
{
    public function __construct(public float $x, public float $y, public float $width, public float $height, public ImageAsset $asset, public ?string $semanticId = null) {}
}
