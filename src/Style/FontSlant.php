<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Style;

/** CSS1 5.2.3 font-style values, also used to label the faces of a family. */
enum FontSlant: string
{
    case Normal = 'normal';
    case Italic = 'italic';
    case Oblique = 'oblique';
}
