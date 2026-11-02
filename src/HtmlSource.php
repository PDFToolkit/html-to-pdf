<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

final readonly class HtmlSource
{
    public function __construct(public string $html, public string $stylesheet = '', public ?string $baseUri = null) {}
}
