<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Support;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Model\HtmlDocument;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\Style\Borders;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\UnsupportedPolicy;

/** Independent browser witnesses for source recovery, atomic cascade and four-sided border geometry. */
final class CssFoundationWitnessExporter
{
    private const string HTML = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>CSS1 foundations</title>
<link rel="stylesheet" href="css/foundation.css">
<style>
* { margin:0; padding:0; border:0 solid black; }
html { color:black; font-size:16px; line-height:32px; }
body { width:720px; padding:16px; }
#invalid-color { color:red; color:bogus; }
#invalid-important { color:red; color:bogus!important; }
#signed-rgb { color:rgb(-20,+260,+0); }
#percent-rgb { color:rgb(-10%,120%,50%); }
#mixed-rgb { color:red; color:rgb(10%,0,0); }
#invalid-rgb-token { color:red; color:rgb(0..1,0,0); }
#invalid-hex { color:red; color:#gg0000; }
#atomic-margin { margin:1px 2px 3px 4px; margin:5px nonsense; }
#atomic-border { border-left:2px dashed red; border-left:3px solid bogus; }
#invalid-list { color:green; }
#invalid-list, :unknown-pseudo { color:red; }
#escaped\:selector { color:#2468ac; }
#recovery { color green; color:#224466; }
#late-import { color:green; }
@import "late.css";
#after-recovery { color:#113355; }
#border-keywords { border-style:solid; border-width:thin medium thick; border-color:red green blue; }
#zero-style { border:0 dashed red; }
#none-style { border:8px none blue; }
#border-parent { border:0 dashed #2468ac; }
#border-inherit { border-style:inherit; border-width:inherit; border-color:inherit; }
#currentcolor { color:#884422; border:1px solid currentcolor; }
#asymmetric { width:200px; height:20px; padding:2px 4px 6px 8px; margin-top:7px; border-width:1px 3px 5px 7px; border-style:solid dashed double dotted; border-color:#112233 #445566 #778899 #aabbcc; }
#transparent-border { width:200px; height:20px; padding:4px; border:5px solid transparent; border-left:7px double #335577; }
#three-dimensional { width:200px; height:20px; border-width:4px 6px 8px 10px; border-style:groove ridge inset outset; border-color:#cc4422 #22cc44 #4422cc #777777; }
</style></head><body>
<p id="invalid-color">Invalid color preserves previous red</p><p id="invalid-important">Invalid important preserves previous red</p>
<p id="signed-rgb">Signed RGB clipping</p><p id="percent-rgb">Percentage RGB clipping</p><p id="mixed-rgb">Mixed RGB units preserve red</p>
<p id="invalid-rgb-token">Invalid RGB token preserves red</p><p id="invalid-hex">Invalid hexadecimal preserves red</p>
<p id="atomic-margin">Atomic margin shorthand</p><p id="atomic-border">Atomic border shorthand</p>
<p id="invalid-list">Invalid selector list preserves green</p><p id="escaped:selector">Escaped identifier selector</p>
<p id="recovery">Recover a valid declaration</p><p id="after-recovery">Recover a later rule</p>
<p id="linked">Explicit linked stylesheet</p><p id="nested-import">Relative nested import</p><p id="print-import">Print import</p><p id="screen-import">Screen import skipped</p><p id="late-import">Late import ignored</p>
<div id="border-keywords"></div><div id="zero-style"></div><div id="none-style"></div><div id="border-parent"><div id="border-inherit"></div></div><div id="currentcolor"></div>
<div id="asymmetric"></div><div id="transparent-border"></div><div id="three-dimensional"></div>
</body></html>
HTML;

    /** @var array<string,string> */
    private const array RESOURCES = [
        'css/foundation.css' => '@import "nested.css" print; @import "../print.css" print; @import "../screen.css" screen; #linked{color:#557799} #screen-import{color:green}',
        'css/nested.css' => '#nested-import{color:#336688}',
        'print.css' => '#print-import{color:#663399}',
        'screen.css' => '#screen-import{color:red}',
        'late.css' => '#late-import{color:red}',
    ];

    /** @var array<string,list<string>> */
    private const array CASES = [
        'invalid-color' => ['invalid specified color'], 'invalid-important' => ['invalid important declaration'],
        'signed-rgb' => ['signed integer RGB clipping'], 'percent-rgb' => ['percentage RGB clipping'],
        'mixed-rgb' => ['mixed RGB units rejected'], 'invalid-rgb-token' => ['raw numeric token validation'],
        'invalid-hex' => ['raw hexadecimal validation'], 'atomic-margin' => ['atomic margin shorthand'],
        'atomic-border' => ['atomic border shorthand'], 'invalid-list' => ['atomic selector list'],
        'escaped:selector' => ['escaped selector identifier'], 'recovery' => ['declaration syntax recovery'],
        'after-recovery' => ['rule following syntax error'], 'linked' => ['explicit linked stylesheet'],
        'nested-import' => ['relative nested import'], 'print-import' => ['print import'],
        'screen-import' => ['screen import skipped'], 'late-import' => ['late import rejected'],
        'border-keywords' => ['thin medium thick', 'four-side tuples'], 'zero-style' => ['zero width retains style'],
        'none-style' => ['none style computes zero width'], 'border-inherit' => ['border inheritance'],
        'currentcolor' => ['currentcolor'], 'asymmetric' => ['asymmetric border geometry', 'solid dashed double dotted'],
        'transparent-border' => ['transparent border preserves geometry'],
        'three-dimensional' => ['groove ridge inset outset', 'asymmetric geometry'],
    ];

    /** @return array<string,mixed> */
    public function export(string $directory, FontSet $fonts): array
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $options = new ConversionOptions($fonts, new PageSettings(600, 1000, new Edges()), resources: new ResourceCatalog(stylesheets: self::RESOURCES), unsupported: UnsupportedPolicy::Report);
        $document = (new HtmlCompiler())->parse(new HtmlSource(self::HTML), $options, $diagnostics);
        $nodes = [];
        $pending = [$document->root];
        while ($pending !== []) {
            $node = array_pop($pending);
            if (($id = $node->attribute('id')) !== null) {
                if (isset($nodes[$id])) {
                    throw new \RuntimeException('Duplicate CSS foundation witness.');
                }
                $nodes[$id] = $node;
            }
            array_push($pending, ...$node->children);
        }
        $geometry = $this->geometry($document, $options, $diagnostics, $nodes);
        $witnesses = [];
        foreach (self::CASES as $id => $features) {
            $node = $nodes[$id] ?? throw new \RuntimeException('Missing CSS foundation witness: ' . $id);
            $values = ['color' => $this->color($node->style->color)];
            if ($id === 'atomic-margin') {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $values['margin-' . $side] = $node->style->margin->$side;
                }
            }
            if (in_array($id, ['atomic-border', 'border-keywords', 'zero-style', 'none-style', 'border-inherit', 'currentcolor', 'asymmetric', 'transparent-border', 'three-dimensional'], true)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $border = $node->style->borderEdges()->$side;
                    $values['border-' . $side . '-width'] = $border->effectiveWidth();
                    $values['border-' . $side . '-style'] = $border->style->value;
                    $values['border-' . $side . '-color'] = $border->color === null ? [0, 0, 0, 0] : $this->color($border->color);
                }
            }
            $witnesses[] = ['id' => $id, 'features' => $features, 'values' => $values, 'geometry' => $geometry[$id] ?? null];
        }
        $resources = [];
        foreach (self::RESOURCES as $uri => $css) {
            $file = 'foundation-resources/' . $uri;
            $this->write($directory . '/' . $file, $css);
            $resources[] = ['uri' => $uri, 'file' => $file, 'bytes' => strlen($css), 'sha256' => hash('sha256', $css)];
        }
        $this->write($directory . '/foundations.html', self::HTML);
        return [
            'htmlFile' => 'foundations.html', 'htmlSha256' => hash('sha256', self::HTML),
            'scope' => 'Source-preserving Report-mode cascade, explicit stylesheet imports, CSS1 RGB and four-sided border computed values. Empty fixed-size boxes compare actual BorderBox commands; no general text or border pixel-equivalence claim.',
            'policy' => 'Report', 'requiredDiagnosticCodes' => ['css.syntax', 'css.value', 'css.selector', 'css.import-order'],
            'diagnostics' => $diagnostics->all(), 'resources' => $resources, 'witnesses' => $witnesses,
        ];
    }

    /** @param array<string,Node> $nodes
     * @return array<string,array{x:float,y:float,width:float,height:float}>
     */
    private function geometry(HtmlDocument $document, ConversionOptions $options, Diagnostics $diagnostics, array $nodes): array
    {
        $targets = [];
        foreach (['asymmetric', 'transparent-border', 'three-dimensional'] as $id) {
            $targets[$this->borderKey($nodes[$id]->style->borderEdges())] = $id;
        }
        $layout = (new LayoutEngine())->layout($document, $options, $diagnostics);
        if (count($layout->pages) !== 1) {
            throw new \RuntimeException('CSS foundation witnesses must fit a single page.');
        }
        $geometry = [];
        foreach ($layout->pages[0]->commands as $command) {
            if (!$command instanceof BorderBox || !isset($targets[$key = $this->borderKey($command->borders)])) {
                continue;
            }
            if ($command->offset !== 0.0 || $command->height !== $command->totalHeight || isset($geometry[$targets[$key]])) {
                throw new \RuntimeException('Expected a unique unsliced CSS border box.');
            }
            $geometry[$targets[$key]] = ['x' => $command->x, 'y' => $command->y, 'width' => $command->width, 'height' => $command->height];
        }
        if (count($geometry) !== count($targets)) {
            throw new \RuntimeException('Missing CSS border box geometry.');
        }
        return $geometry;
    }

    private function borderKey(Borders $borders): string
    {
        return json_encode($borders, JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function color(Color $color): array
    {
        return array_map(static fn(float $channel): int => (int) round($channel * 255), [$color->red, $color->green, $color->blue]);
    }

    private function write(string $path, string $bytes): void
    {
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException('Cannot create CSS witness resource directory.');
        }
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Cannot write CSS witness resource.');
        }
    }
}
