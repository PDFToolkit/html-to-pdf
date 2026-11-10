<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

final readonly class ComputedStyle
{
    public function __construct(public string $display = 'inline', public float $fontSize = 12, public float $lineHeight = 16, public int $fontWeight = 400, public bool $italic = false, public Color $color = new Color(), public ?Color $background = null, public string $textAlign = 'left', public string $whiteSpace = 'normal', public bool $underline = false, public Edges $margin = new Edges(), public Edges $padding = new Edges(), public float $borderWidth = 0, public Color $borderColor = new Color(), public ?Length $width = null, public ?Length $height = null, public string $breakBefore = 'auto', public string $breakAfter = 'auto', public bool $breakInsideAvoid = false, public int $orphans = 2, public int $widows = 2, public string $listStyleType = 'disc', public string $verticalAlign = 'top', public string $tableLayout = 'auto', public ?Borders $borders = null, public string $float = 'none', public string $clear = 'none', public FontFamilyList $fontFamily = new FontFamilyList(), public TextSpacing $textSpacing = new TextSpacing(), public TextIndent $textIndent = new TextIndent(), public ?FontSlant $slant = null) {}

    /** The three CSS1 styles; the boolean $italic remains the projection used by the 0.1 constructor. */
    public function fontSlant(): FontSlant
    {
        return $this->slant ?? ($this->italic ? FontSlant::Italic : FontSlant::Normal);
    }

    /** Independent sides take precedence; the uniform 0.1 constructor remains usable. */
    public function borderEdges(): Borders
    {
        return $this->borders ?? Borders::uniform(new BorderSide($this->borderWidth, BorderStyle::Solid, $this->borderColor));
    }
}
