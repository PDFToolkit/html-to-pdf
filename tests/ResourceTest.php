<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Resource\ResourceBudget;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PHPUnit\Framework\TestCase;

final class ResourceTest extends TestCase
{
    public function testCatalogNeverResolvesPathsOrRemoteUrls(): void
    {
        $this->expectException(ConversionException::class);
        (new ResourceCatalog())->stylesheet('file:///etc/passwd');
    }

    public function testExplicitNamesAreOpaqueAndExact(): void
    {
        $catalog = new ResourceCatalog(stylesheets: ['https://invalid.example/style.css' => 'p { color: red; }']);
        self::assertSame('p { color: red; }', $catalog->stylesheet('https://invalid.example/style.css'));
        self::assertSame(17, $catalog->totalBytes());
    }

    public function testMalformedImageFailsBeforeConversion(): void
    {
        $this->expectException(ConversionException::class);
        new ImageAsset('not an image');
    }

    public function testImageByteBudgetIsAppliedBeforeHeaderParsing(): void
    {
        $this->expectException(LimitExceeded::class);
        new ImageAsset('not an image', maxBytes: 3);
    }

    public function testCatalogBudgetIncludesUnusedCss(): void
    {
        $this->expectException(LimitExceeded::class);
        (new ResourceBudget(new ConversionLimits(maxResourceBytes: 10)))
            ->check(new ResourceCatalog(stylesheets: ['unused.css' => str_repeat(' ', 11)]));
    }

    public function testPixelBudgetAccumulatesAcrossCatalogEntries(): void
    {
        $image = new ImageAsset(file_get_contents(__DIR__ . '/Fixtures/images/rgba.png'));
        $this->expectException(LimitExceeded::class);
        (new ResourceBudget(new ConversionLimits(maxImagePixels: $image->width * $image->height)))
            ->check(new ResourceCatalog(images: ['one' => $image, 'two' => $image]));
    }
}
