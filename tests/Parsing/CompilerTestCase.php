<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Parsing;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Model\HtmlDocument;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\TestCase;

abstract class CompilerTestCase extends TestCase
{
    private static FontSet $fonts;

    public static function setUpBeforeClass(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf');
        self::assertIsString($bytes);
        self::$fonts = new FontSet(new FontFace($bytes));
    }

    protected function options(?ConversionLimits $limits = null, ?ResourceCatalog $resources = null, UnsupportedPolicy $policy = UnsupportedPolicy::Reject): ConversionOptions
    {
        return new ConversionOptions(self::$fonts, resources: $resources ?? new ResourceCatalog(), limits: $limits ?? new ConversionLimits(), unsupported: $policy);
    }

    protected function compile(string $html, string $css = '', ?ConversionOptions $options = null, ?Diagnostics $diagnostics = null): HtmlDocument
    {
        $options ??= $this->options();
        return (new HtmlCompiler())->parse(new HtmlSource($html, $css), $options, $diagnostics ?? new Diagnostics($options->unsupported));
    }

    protected function find(Node $node, string $id): Node
    {
        if ($node->attribute('id') === $id) {
            return $node;
        }
        foreach ($node->children as $child) {
            try {
                return $this->find($child, $id);
            } catch (\OutOfBoundsException) {
            }
        }
        throw new \OutOfBoundsException($id);
    }
}
