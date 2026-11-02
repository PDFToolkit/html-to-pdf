<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

final readonly class ComputedStyle
{
    public function __construct(public string $display = 'inline', public float $fontSize = 12, public float $lineHeight = 16, public int $fontWeight = 400, public bool $italic = false, public Color $color = new Color(), public ?Color $background = null, public string $textAlign = 'left', public string $whiteSpace = 'normal', public bool $underline = false, public Edges $margin = new Edges(), public Edges $padding = new Edges(), public float $borderWidth = 0, public Color $borderColor = new Color(), public ?Length $width = null, public ?Length $height = null, public string $breakBefore = 'auto', public string $breakAfter = 'auto', public bool $breakInsideAvoid = false, public int $orphans = 2, public int $widows = 2, public string $listStyleType = 'disc', public string $verticalAlign = 'top', public string $tableLayout = 'auto') {}
}
