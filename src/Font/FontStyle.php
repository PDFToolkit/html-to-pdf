<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Font;

enum FontStyle: string
{
    case Regular = 'regular';
    case Bold = 'bold';
    case Italic = 'italic';
    case BoldItalic = 'bold-italic';
}
