<?php

declare(strict_types=1);

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Layout\LayoutEngine;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Style\Edges;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Model\Node;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Style\Color;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Original, deliberately author-controlled CSS witnesses, independent of PDF painting. */
final class StyleWitnessExporter
{
    private const string HTML = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Native PHP CSS witnesses</title><style>
* { margin: 0; padding: 0; border: 0 solid black; }
html { font-size: 16px; line-height: 1.5; color: #243447; }
body { width: 800px; padding: 16px; }
h1 { font-size: 24px; margin-bottom: 12px; }
p { margin-bottom: 4px; }
.row { color: #345678; }
#specificity { color: #006633; }
.a.b.c.d.e.f.g.h.i.j.k.l { color: red; }
.important { color: #336699 !important; }
#inline-important { color: red !important; }
.order { color: red; }
p.order { color: green; }
p.order { color: #663399; }
.parent { font-size: 20px; line-height: 1.4; color: #228844; font-weight: 700; font-style: italic; }
.parent .inherited { font-size: 150%; }
#explicit-inherit { color: inherit; font-weight: inherit; font-style: inherit; }
#unset { color: unset; font-weight: unset; font-style: unset; }
#initial { color: initial; font-weight: initial; font-style: initial; }
#em { font-size: 1.25em; margin-left: 2em; }
#rem { font-size: 1.5rem; padding-left: 2rem; }
.length-parent { font-size: 20px; line-height: 24px; }
#length-child { font-size: 150%; }
#units { width: 2in; height: 12pt; margin: 1pc 2.54cm 25.4mm 16px; padding: 2pt 4px 0.5em 8px; }
#box { width: 240px; height: 32px; padding: 4px 8px 12px 16px; border: 4px solid #123456; margin: 8px 12px; background: #ddeeff; }
#longhand { margin: 8px; margin-left: 20px; padding: 4px 8px 12px; border: 4px solid currentcolor; color: #884422; }
#percent-parent { width: 400px; }
#percent { width: 50%; height: 20px; padding: 4px; border: 4px solid black; }
#text { text-align: right; white-space: pre-wrap; text-decoration: underline; }
#media { color: #112233; }
@media screen { #media { color: red; font-size: 99px; } }
@media print { #media { color: #335577; font-size: 18px; } }
@media not print { #media { color: red; } }
.descendant p { color: #804020; }
.children > p { font-size: 18px; }
.children p + p { font-weight: 700; }
.children p ~ p { font-style: italic; }
.children p:nth-child(3) { color: #408020; }
p[title="colon:value"] { color: #2255aa; }
#where { color: #553311; }
:where(#where) { color: red; }
#is { color: red; }
:is(#absent, .is-target) { color: #334455; }
.not-target:not(.excluded) { color: #448866; }
</style><style media="print">#style-media { color: #775533; }</style></head><body>
<h1>Native PHP / browser CSS witnesses</h1>
<p id="specificity" class="a b c d e f g h i j k l">ID specificity</p>
<p id="important" class="important" style="color: red">Author important beats inline normal</p>
<p id="inline-important" style="color: #116644 !important">Inline important</p>
<p id="order" class="order">Source order</p>
<div class="parent"><p id="inherit" class="inherited">Relative font and inherited unitless line height</p><p id="explicit-inherit">Explicit inheritance</p><p id="unset">Unset inherited properties</p><p id="initial">Initial values</p><p id="em">Local em</p><p id="rem">Root rem</p></div>
<div class="length-parent"><p id="length-child">Inherited fixed line height</p></div>
<p id="units">Absolute units</p>
<div id="box">Content, padding and border box</div>
<p id="longhand">Shorthand and longhand</p>
<div id="percent-parent"><div id="percent">Percentage width</div></div>
<p id="text">Text properties</p><p id="media">Print media</p><p id="style-media">Style media attribute</p>
<div class="descendant"><section><p id="descendant">Descendant selector</p></section></div>
<div class="children"><p id="child">Child selector</p><p id="adjacent">Adjacent and general sibling</p><p id="nth">Nth child</p></div>
<p id="attribute" title="colon:value">Attribute selector</p><p id="where">Zero specificity where</p><p id="is" class="is-target">Maximum specificity is</p><p id="not" class="not-target">Negation</p>
</body></html>
HTML;

    /** @var array<string, array{tier:string, features:list<string>}> */
    private const array CASES = [
        'specificity' => ['tier' => 'CSS1', 'features' => ['ID versus class specificity']],
        'important' => ['tier' => 'CSS1', 'features' => ['important author declaration versus inline normal']],
        'inline-important' => ['tier' => 'CSS1', 'features' => ['important inline declaration']],
        'order' => ['tier' => 'CSS1', 'features' => ['type/class specificity', 'source order']],
        'inherit' => ['tier' => 'CSS1', 'features' => ['inheritance', 'percentage font size', 'inherited unitless line height']],
        'explicit-inherit' => ['tier' => 'later-extension', 'features' => ['explicit inherit keyword']],
        'unset' => ['tier' => 'later-extension', 'features' => ['unset keyword']],
        'initial' => ['tier' => 'later-extension', 'features' => ['initial keyword']],
        'em' => ['tier' => 'CSS1', 'features' => ['em font size', 'em margin']],
        'rem' => ['tier' => 'later-extension', 'features' => ['rem font size', 'rem padding']],
        'length-child' => ['tier' => 'CSS1', 'features' => ['inherited fixed line height']],
        'units' => ['tier' => 'CSS1', 'features' => ['px', 'pt', 'in', 'pc', 'cm', 'mm', 'margin shorthand', 'padding shorthand']],
        'box' => ['tier' => 'CSS1', 'features' => ['width', 'height', 'margin', 'padding', 'solid uniform border', 'background color', 'content-box geometry']],
        'longhand' => ['tier' => 'CSS1-and-later', 'features' => ['shorthand versus longhand', 'currentcolor extension']],
        'percent' => ['tier' => 'CSS1', 'features' => ['percentage width', 'content-box geometry']],
        'text' => ['tier' => 'CSS1-and-later', 'features' => ['text align', 'underline', 'pre-wrap extension']],
        'media' => ['tier' => 'later-extension', 'features' => ['print versus screen media']],
        'style-media' => ['tier' => 'later-extension', 'features' => ['style media attribute']],
        'descendant' => ['tier' => 'CSS1', 'features' => ['descendant selector']],
        'child' => ['tier' => 'later-extension', 'features' => ['child selector']],
        'adjacent' => ['tier' => 'later-extension', 'features' => ['adjacent sibling', 'general sibling']],
        'nth' => ['tier' => 'later-extension', 'features' => ['nth-child']],
        'attribute' => ['tier' => 'later-extension', 'features' => ['attribute selector with colon in string']],
        'where' => ['tier' => 'later-extension', 'features' => ['where zero specificity']],
        'is' => ['tier' => 'later-extension', 'features' => ['is maximum specificity']],
        'not' => ['tier' => 'later-extension', 'features' => ['not selector']],
    ];

    /** @var array<string, Node> */
    private array $nodes = [];

    public function export(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create witness output directory.');
        }
        $fontBytes = file_get_contents(__DIR__ . '/Fixtures/fonts/NotoSans-Regular.ttf');
        if ($fontBytes === false) {
            throw new RuntimeException('Cannot read explicitly supplied witness font.');
        }
        $diagnostics = new Diagnostics();
        $document = (new HtmlCompiler())->parse(new HtmlSource(self::HTML), new ConversionOptions(new FontSet(new FontFace($fontBytes))), $diagnostics);
        $this->collect($document->root);
        $witnesses = [];
        foreach (self::CASES as $id => $case) {
            $node = $this->nodes[$id] ?? throw new RuntimeException('Missing compiler witness: ' . $id);
            $style = $node->style;
            $values = [
                'font-size' => $style->fontSize, 'line-height' => $style->lineHeight,
                'font-weight' => (string) $style->fontWeight, 'font-style' => $style->italic ? 'italic' : 'normal',
                'color' => $this->color($style->color),
            ];
            if (in_array($id, ['em', 'rem', 'units', 'box', 'longhand', 'percent'], true)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $values['margin-' . $side] = $style->margin->$side;
                    $values['padding-' . $side] = $style->padding->$side;
                    $values['border-' . $side . '-width'] = $style->borderWidth;
                    if ($style->borderWidth > 0) {
                        $values['border-' . $side . '-color'] = $this->color($style->borderColor);
                    }
                }
            }
            if (in_array($id, ['units', 'box', 'percent'], true)) {
                $values['width'] = $style->width?->resolve(300);
                $values['height'] = $style->height?->value;
            }
            if ($id === 'box') {
                $values['background-color'] = $this->color($style->background ?? throw new RuntimeException('Expected explicit background.'));
            }
            if ($id === 'text') {
                $values['text-align'] = $style->textAlign;
                $values['white-space'] = $style->whiteSpace;
                $values['text-decoration-line'] = $style->underline ? 'underline' : 'none';
            }
            $geometry = null;
            if (in_array($id, ['box', 'percent'], true)) {
                $geometry = [
                    'width' => ($style->width ?? throw new RuntimeException('Expected an explicit width.'))->resolve(300) + $style->padding->horizontal() + 2 * $style->borderWidth,
                    'height' => ($style->height ?? throw new RuntimeException('Expected an explicit height.'))->value + $style->padding->vertical() + 2 * $style->borderWidth,
                ];
            }
            $witnesses[] = ['id' => $id, ...$case, 'values' => $values, 'geometry' => $geometry];
        }
        $report = [
            'schemaVersion' => 1, 'phpVersion' => PHP_VERSION, 'scope' => 'Selected computed CSS values and simple author-sized content boxes; not complete CSS conformance or browser/PDF layout parity.',
            'references' => ['CSS1' => 'https://www.w3.org/TR/2008/REC-CSS1-20080411/'],
            'htmlFile' => 'styles.html', 'htmlSha256' => hash('sha256', self::HTML), 'media' => 'print',
            'diagnostics' => $diagnostics->all(), 'witnesses' => $witnesses,
            'layoutFixture' => (new LayoutWitnessExporter())->export($directory, new FontSet(new FontFace($fontBytes))),
        ];
        if (file_put_contents($directory . '/styles.html', self::HTML) === false
            || file_put_contents($directory . '/styles.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException('Cannot write style witnesses.');
        }
        echo json_encode(['witnesses' => count($witnesses), 'output' => $directory . '/styles.json'], JSON_THROW_ON_ERROR), "\n";
    }

    private function collect(Node $node): void
    {
        if (($id = $node->attribute('id')) !== null) {
            if (isset($this->nodes[$id])) {
                throw new RuntimeException('Duplicate witness identity.');
            }
            $this->nodes[$id] = $node;
        }
        foreach ($node->children as $child) {
            $this->collect($child);
        }
    }

    /** @return list<int> */
    private function color(Color $color): array
    {
        return array_map(static fn(float $channel): int => (int) round($channel * 255), [$color->red, $color->green, $color->blue]);
    }
}

/** Empty author-sized boxes isolate geometry from platform font metrics and pagination. */
final class LayoutWitnessExporter
{
    private const string HTML = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Positive adjoining margins</title><style>
* { margin: 0; padding: 0; border: 0 solid black; }
body { width: 720px; padding: 16px; }
#first { height: 20px; background: #113355; margin-bottom: 12px; }
#sibling { height: 20px; background: #225566; margin-top: 24px; margin-bottom: 8px; }
#parent { background: #337788; margin-top: 16px; margin-bottom: 12px; }
#child { height: 16px; background: #4499aa; margin-top: 32px; margin-bottom: 20px; }
#empty { margin-top: 14px; margin-bottom: 30px; }
#after-empty { height: 20px; background: #55aabb; margin-top: 18px; margin-bottom: 8px; }
#padded { background: #66bbcc; padding: 8px; margin-top: 12px; margin-bottom: 12px; }
#padded-child { height: 16px; background: #77ccdd; margin-top: 20px; margin-bottom: 24px; }
#bordered { background: #88ddee; border: 4px solid #000000; margin-top: 16px; margin-bottom: 8px; }
#bordered-child { height: 16px; background: #99aacc; margin-top: 20px; margin-bottom: 24px; }
#fixed { height: 60px; background: #aa88cc; margin-top: 20px; margin-bottom: 8px; }
#fixed-child { height: 16px; background: #bb77aa; margin-top: 12px; margin-bottom: 24px; }
#last { height: 20px; background: #cc6699; margin-top: 16px; }
</style></head><body><div id="first"></div><div id="sibling"></div><div id="parent"><div id="child"></div></div><div id="empty"></div><div id="after-empty"></div><div id="padded"><div id="padded-child"></div></div><div id="bordered"><div id="bordered-child"></div></div><div id="fixed"><div id="fixed-child"></div></div><div id="last"></div></body></html>
HTML;

    /** @return array<string, mixed> */
    public function export(string $directory, FontSet $fonts): array
    {
        $diagnostics = new Diagnostics();
        $options = new ConversionOptions($fonts, new PageSettings(600, 900, new Edges()));
        $document = (new HtmlCompiler())->parse(new HtmlSource(self::HTML), $options, $diagnostics);
        $targets = [];
        $pending = [$document->root];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node->style->background !== null && ($id = $node->attribute('id')) !== null) {
                $targets[$this->colorKey($node->style->background)] = $id;
            }
            array_push($pending, ...$node->children);
        }
        $layout = (new LayoutEngine())->layout($document, $options, $diagnostics);
        if (count($layout->pages) !== 1) {
            throw new RuntimeException('The geometry witness must fit one page without pagination.');
        }
        $boxes = [];
        foreach ($layout->pages[0]->commands as $command) {
            if (!$command instanceof Rectangle || !isset($targets[$key = $this->colorKey($command->color)])) {
                continue;
            }
            $id = $targets[$key];
            $bounds = [$command->x, $command->y, $command->x + $command->width, $command->y + $command->height];
            $previous = $boxes[$id] ?? $bounds;
            $boxes[$id] = [min($previous[0], $bounds[0]), min($previous[1], $bounds[1]), max($previous[2], $bounds[2]), max($previous[3], $bounds[3])];
        }
        $witnesses = [];
        foreach ($targets as $id) {
            [$x, $y, $right, $bottom] = $boxes[$id] ?? throw new RuntimeException('Missing layout background witness: ' . $id);
            $witnesses[] = ['id' => $id, 'values' => ['x' => $x, 'y' => $y, 'width' => $right - $x, 'height' => $bottom - $y]];
        }
        if (file_put_contents($directory . '/layout.html', self::HTML) === false) {
            throw new RuntimeException('Cannot write layout witness.');
        }

        return [
            'htmlFile' => 'layout.html', 'htmlSha256' => hash('sha256', self::HTML),
            'scope' => 'CSS1 positive sibling, parent/child and empty-block adjoining margins; padding, border and definite-height barriers. Empty boxes, no text metrics, no pagination.',
            'pagePoints' => ['width' => 600, 'height' => 900], 'diagnostics' => $diagnostics->all(), 'witnesses' => $witnesses,
        ];
    }

    private function colorKey(Color $color): string
    {
        return implode(',', array_map(static fn(float $channel): int => (int) round($channel * 255), [$color->red, $color->green, $color->blue]));
    }
}

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php tests/export-style-witnesses.php OUTPUT_DIRECTORY\n");
    exit(2);
}
(new StyleWitnessExporter())->export($argv[1]);
