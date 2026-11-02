<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Resource;

use PDF\HtmlToPdf\Exception\ConversionException;

final readonly class ResourceCatalog
{
    /**
     * @param array<string,ImageAsset> $images
     * @param array<string,string> $stylesheets
     */
    public function __construct(private array $images = [], private array $stylesheets = [])
    {
        foreach ($images as $name => $image) {
            $this->requireImage($name, $image);
        }
        foreach ($stylesheets as $name => $css) {
            $this->requireStylesheet($name, $css);
        }
    }
    private function requireImage(mixed $name, mixed $image): void
    {
        if (!is_string($name) || !$image instanceof ImageAsset) {
            throw new \InvalidArgumentException('Named image assets expected.');
        }
    }
    private function requireStylesheet(mixed $name, mixed $css): void
    {
        if (!is_string($name) || !is_string($css)) {
            throw new \InvalidArgumentException('Named stylesheets expected.');
        }
    }
    public function image(string $uri): ImageAsset
    {
        return $this->images[$uri] ?? throw new ConversionException('No explicitly supplied image resource: ' . $uri);
    }
    public function stylesheet(string $uri): string
    {
        return $this->stylesheets[$uri] ?? throw new ConversionException('No explicitly supplied CSS resource: ' . $uri);
    }
    public function totalBytes(): int
    {
        return array_sum(array_map(static fn(ImageAsset $asset): int => strlen($asset->bytes), $this->images)) + array_sum(array_map(strlen(...), $this->stylesheets));
    }
    /** @return array<string,ImageAsset>
     */ public function images(): array
    {
        return $this->images;
    }
}
