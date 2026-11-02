<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Resource;

use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;

final readonly class ImageAsset
{
    public int $width;
    public int $height;
    public string $mediaType;
    public function __construct(public string $bytes, int $maxBytes = 16777216, int $maxPixels = 16000000)
    {
        if ($maxBytes < 1 || $maxPixels < 1) {
            throw new \InvalidArgumentException('Image budgets must be positive.');
        }
        if (strlen($bytes) > $maxBytes) {
            throw new LimitExceeded('Image byte budget exceeded.');
        }
        $data = @getimagesizefromstring($bytes);
        if ($data === false || !in_array($data['mime'], ['image/png','image/jpeg'], true) || $data[0] < 1 || $data[1] < 1) {
            throw new ConversionException('Expected a valid PNG or JPEG image.');
        }
        if ($data[1] > intdiv($maxPixels, $data[0])) {
            throw new LimitExceeded('Image pixel budget exceeded.');
        }
        $this->width = $data[0];
        $this->height = $data[1];
        $this->mediaType = $data['mime'];
    }
}
