<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

/** Form rendering is opt-in and never creates submission actions or PDF fields. */
enum FormControls: string
{
    case Reject = 'reject';
    case StaticSnapshot = 'static-snapshot';
}
