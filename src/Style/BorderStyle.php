<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

enum BorderStyle: string
{
    case None = 'none';
    case Dotted = 'dotted';
    case Dashed = 'dashed';
    case Solid = 'solid';
    case Double = 'double';
    case Groove = 'groove';
    case Ridge = 'ridge';
    case Inset = 'inset';
    case Outset = 'outset';
}
