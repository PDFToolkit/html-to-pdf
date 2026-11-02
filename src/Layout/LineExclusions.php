<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

/** A block's horizontal bounds viewed through its shared float context. */
final readonly class LineExclusions
{
    public function __construct(private FloatContext $floats, private float $x, private float $width) {}

    public function space(float $top, float $height, float $minimumWidth = 0): LineSpace
    {
        return $this->floats->space($this->x, $this->width, $top, $height, $minimumWidth);
    }

    public function withBounds(float $x, float $width): self
    {
        return new self($this->floats, $x, $width);
    }

    public function context(): FloatContext
    {
        return $this->floats;
    }
}
