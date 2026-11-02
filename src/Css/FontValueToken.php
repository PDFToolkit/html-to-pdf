<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

final readonly class FontValueToken
{
    public function __construct(public string $value, public bool $quoted = false) {}
}
