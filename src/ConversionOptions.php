<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Resource\ResourceCatalog;

final readonly class ConversionOptions
{
    public function __construct(public FontSet $fonts, public PageSettings $page = new PageSettings(), public ResourceCatalog $resources = new ResourceCatalog(), public ConversionLimits $limits = new ConversionLimits(), public UnsupportedPolicy $unsupported = UnsupportedPolicy::Reject) {}
}
