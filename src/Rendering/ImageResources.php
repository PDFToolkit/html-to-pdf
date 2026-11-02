<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\Document\Images\ImageXObjectFactory;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Images\JpegImageReader;
use PDF\Images\PngImageDecoder;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class ImageResources
{
    /** @var \SplObjectStorage<ImageAsset, NameType> */
    private \SplObjectStorage $names;
    /** @var list<Entry> */
    private array $entries = [];

    public function __construct(private readonly RenderBudget $budget)
    {
        $this->names = new \SplObjectStorage();
    }

    public function prepare(ImageAsset $asset): void
    {
        if ($this->names->offsetExists($asset)) {
            return;
        }
        $this->budget->resource(strlen($asset->bytes), $asset->width * $asset->height);
        $bound = $this->budget->limits;
        $limits = new ImageImportLimits(
            maxEncodedBytes: $bound->maxResourceBytes,
            maxDecodedBytes: $bound->maxOutputBytes,
            maxPixels: $bound->maxImagePixels,
            maxCumulativeDecodedBytes: $bound->maxOutputBytes,
        );
        $bytes = new ImageFileBytes($asset->bytes);
        $image = $asset->mediaType === 'image/png' ? (new PngImageDecoder($limits))->decode($bytes) : (new JpegImageReader($limits))->read($bytes);
        $stream = (new ImageXObjectFactory())->create($image);
        $name = new NameType('Im' . (count($this->names) + 1));
        $this->names[$asset] = $name;
        $this->entries[] = new Entry($name, new IndirectReference($stream));
    }

    public function name(ImageAsset $asset): NameType
    {
        return $this->names[$asset];
    }

    public function dictionary(): DictionaryType
    {
        return new DictionaryType(...$this->entries);
    }
}
