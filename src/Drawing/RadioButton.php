<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Drawing;

/** Explicit static snapshot; this command never creates an interactive form field. */
final readonly class RadioButton implements Command
{
    public function __construct(public float $x, public float $y, public float $width, public float $height, public bool $checked = false, public bool $disabled = false, public ?string $semanticId = null) {}
}
