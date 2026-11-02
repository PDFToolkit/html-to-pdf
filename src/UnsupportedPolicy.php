<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

enum UnsupportedPolicy
{
    case Reject;
    case Report;
}
