<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Exception\LimitExceeded;

/** Shared cumulative accounting for one render, never retained by the renderer. */
final class RenderBudget
{
    private int $resourceBytes = 0;
    private int $imagePixels = 0;
    private int $glyphs = 0;

    public function __construct(public readonly ConversionLimits $limits) {}

    public function resource(int $bytes, int $pixels = 0): void
    {
        if ($bytes < 0 || $pixels < 0 || $bytes > $this->limits->maxResourceBytes - $this->resourceBytes
            || $pixels > $this->limits->maxImagePixels - $this->imagePixels) {
            throw new LimitExceeded('Cumulative font/image resource budget exceeded.');
        }
        $this->resourceBytes += $bytes;
        $this->imagePixels += $pixels;
    }

    public function glyphs(int $count): void
    {
        if ($count > $this->limits->maxTextCharacters - $this->glyphs) {
            throw new LimitExceeded('Cumulative rendered glyph budget exceeded.');
        }
        $this->glyphs += $count;
    }
}
