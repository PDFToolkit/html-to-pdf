<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Rendering\Renderer;
use PDF\HtmlToPdf\Resource\ResourceBudget;

/** A fresh pipeline per conversion: no retained DOM, font embedding or layout state. */
final class Converter
{
    public function convert(HtmlSource $source, ConversionOptions $options): ConversionResult
    {
        (new ResourceBudget($options->limits))->check($options->resources);
        $diagnostics = new Diagnostics($options->unsupported);
        $document = (new HtmlCompiler())->parse($source, $options, $diagnostics);
        $pages = (new LayoutEngine())->layout($document, $options, $diagnostics);
        $pdf = (new Renderer($options->limits))->render($pages);

        return new ConversionResult($pdf, count($pages->pages), $diagnostics->all());
    }
}
