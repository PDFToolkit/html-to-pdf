<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/** A bounded parser witness per inventory property, explicitly not complete CSS conformance. */
final class Css1RegistryTest extends CompilerTestCase
{
    /** @return iterable<string,array{string,string,string,string}> */
    public static function propertySamples(): iterable
    {
        $registry = json_decode(file_get_contents(__DIR__ . '/../../resources/css-coverage.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($registry['requirements'] as $requirement) {
            if ($requirement['kind'] === 'property' && str_starts_with($requirement['id'], 'css1.')) {
                yield $requirement['id'] => [$requirement['name'], $requirement['sample'], $requirement['context'], $requirement['status']];
            }
        }
    }

    #[DataProvider('propertySamples')]
    public function testInventorySampleMatchesItsDeclaredParserBoundary(string $property, string $sample, string $context, string $status): void
    {
        self::assertContains($status, ['partial', 'unsupported'], 'Updating a status requires replacing this witness with the corresponding qualification.');
        $html = $context === 'td'
            ? '<table><tbody><tr><td id="subject">Witness</td></tr></tbody></table>'
            : '<div id="subject">Witness</div>';
        $html = '<!doctype html><html lang="en"><head><title>CSS inventory witness</title></head><body>' . $html . '</body></html>';
        $base = match ($property) {
            'border-width', 'border-color' => 'border-style:solid;',
            'border-style' => 'border-width:1pt;',
            default => '',
        };
        if (preg_match('/^border-(top|right|bottom|left)-width$/D', $property, $match) === 1) {
            $base = 'border-' . $match[1] . '-style:solid;';
        }
        $css = '#subject{' . $base . $property . ':' . $sample . '}';
        if ($status === 'unsupported') {
            $this->expectException(UnsupportedFeature::class);
            $this->compile($html, $css);
            return;
        }
        $diagnostics = new Diagnostics();
        $document = $this->compile($html, $css, diagnostics: $diagnostics);
        self::assertSame([], $diagnostics->all());
        self::assertSame('CSS inventory witness', $document->title);
        $style = $this->find($document->root, 'subject')->style;
        $this->assertComputedWitness($property, $style);
    }

    public function testTheParserWitnessDoesNotDeclareTheLevelComplete(): void
    {
        $registry = json_decode(file_get_contents(__DIR__ . '/../../resources/css-coverage.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('css1', $registry['activeStage']);
        self::assertFalse($registry['stages'][0]['claimedComplete']);
        self::assertCount(53, iterator_to_array(self::propertySamples()));
    }

    public function testReportPolicyKeepsUnsupportedPropertyDiagnosisVisible(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<div id="subject">Witness</div>', '#subject{position:absolute;color:#176a80}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertNotEmpty($diagnostics->all());
        self::assertSame('css.property', $diagnostics->all()[0]->code);
        self::assertEquals(new Color(23 / 255, 106 / 255, 128 / 255), $this->find($document->root, 'subject')->style->color);
    }

    private function assertFontWitness(ComputedStyle $style): void
    {
        self::assertSame(12.0, $style->fontSize);
        self::assertTrue($style->italic);
        self::assertSame('serif', $style->fontFamily->css());
    }

    private function assertComputedWitness(string $property, ComputedStyle $style): void
    {
        if (preg_match('/^border-(top|right|bottom|left)(?:-width)?$/D', $property, $match) === 1) {
            $border = $style->borderEdges()->{$match[1]};
            self::assertSame(1.0, $border->effectiveWidth());
            self::assertSame('solid', $border->style->value);
            return;
        }
        if (str_starts_with($property, 'margin') || str_starts_with($property, 'padding')) {
            [$box, $side] = array_pad(explode('-', $property, 2), 2, null);
            $edges = $box === 'margin' ? $style->margin : $style->padding;
            foreach ($side === null ? ['top', 'right', 'bottom', 'left'] : [$side] as $edge) {
                self::assertSame(6.0, $edges->{$edge});
            }
            return;
        }
        match ($property) {
            'font-family' => self::assertSame('serif', $style->fontFamily->css()),
            'font' => $this->assertFontWitness($style),
            'float' => self::assertSame('left', $style->float),
            'clear' => self::assertSame('both', $style->clear),
            'font-style' => self::assertTrue($style->italic),
            'font-weight' => self::assertSame(700, $style->fontWeight),
            'font-size' => self::assertSame(18.0, $style->fontSize),
            'color' => self::assertEquals(new Color(23 / 255, 106 / 255, 128 / 255), $style->color),
            'background-color' => self::assertEquals(new Color(231 / 255, 239 / 255, 244 / 255), $style->background),
            'background' => self::assertEquals(new Color(1, 1, 1), $style->background),
            'text-decoration' => self::assertTrue($style->underline),
            'vertical-align' => self::assertSame('top', $style->verticalAlign),
            'text-align' => self::assertSame('center', $style->textAlign),
            'letter-spacing' => self::assertSame(1.0, $style->textSpacing->letterSpacing),
            'word-spacing' => self::assertSame(2.0, $style->textSpacing->wordSpacing),
            'text-indent' => self::assertSame(12.0, $style->textIndent->resolve(200)),
            'line-height' => self::assertSame(18.0, $style->lineHeight),
            'border', 'border-width', 'border-style' => self::assertSame(1.0, $style->borderWidth),
            'border-color' => self::assertEquals(new Color(23 / 255, 106 / 255, 128 / 255), $style->borderColor),
            'width' => self::assertSame(30.0, $style->width?->resolve(200)),
            'height' => self::assertSame(30.0, $style->height?->resolve(200)),
            'display' => self::assertSame('block', $style->display),
            'white-space' => self::assertSame('pre', $style->whiteSpace),
            'list-style-type' => self::assertSame('decimal', $style->listStyleType),
            default => self::fail('Missing explicit witness assertion for ' . $property),
        };
    }
}
