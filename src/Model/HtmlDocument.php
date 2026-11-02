<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Model;

final readonly class HtmlDocument
{
    public function __construct(public Node $root, public string $title = '', public string $language = 'en') {}
}
