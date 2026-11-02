<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Resource;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Exception\LimitExceeded;

/** Counts the whole explicitly supplied catalog, including unused resources. */
final readonly class ResourceBudget
{
    public function __construct(private ConversionLimits $limits) {}

    public function check(ResourceCatalog $catalog): void
    {
        if ($catalog->totalBytes() > $this->limits->maxResourceBytes) {
            throw new LimitExceeded('The resource catalog exceeds the cumulative byte budget.');
        }
        $pixels = 0;
        foreach ($catalog->images() as $image) {
            if ($image->height > intdiv($this->limits->maxImagePixels - $pixels, $image->width)) {
                throw new LimitExceeded('The resource catalog exceeds the cumulative image pixel budget.');
            }
            $pixels += $image->width * $image->height;
        }
    }
}
