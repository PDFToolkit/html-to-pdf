<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Model;

use PDF\HtmlToPdf\Style\Color;

final readonly class HtmlDocument
{
    public function __construct(public Node $root, public string $title = '', public string $language = 'en', public ?Color $canvasBackground = null) {}
}
