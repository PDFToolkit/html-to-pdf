<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Css\Declarations;
use PDF\HtmlToPdf\Css\FontSizes;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/** CSS1 5.2.6 absolute and relative size keywords, including their use in the font shorthand. */
final class FontSizeKeywordsTest extends CompilerTestCase
{
    /** The UA table in points for a 16px medium: 9, 10, 13, 16, 18, 24 and 32 CSS px. */
    private const array TABLE = ['xx-small' => 6.75, 'x-small' => 7.5, 'small' => 9.75, 'medium' => 12.0, 'large' => 13.5, 'x-large' => 18.0, 'xx-large' => 24.0];

    /** @return iterable<string,array{string,float}> */
    public static function absoluteKeywords(): iterable
    {
        foreach (self::TABLE as $keyword => $points) {
            yield $keyword => [$keyword, $points];
        }
    }

    #[DataProvider('absoluteKeywords')]
    public function testAbsoluteKeywordIndexesTheTableWhateverTheParentSize(string $keyword, float $points): void
    {
        $document = $this->compile('<div id="parent"><p id="subject">Text</p></div><p id="root">Text</p>', '#parent{font-size:40px}#subject,#root{font-size:' . $keyword . '}');
        self::assertSame($points, $this->find($document->root, 'subject')->style->fontSize);
        self::assertSame($points, $this->find($document->root, 'root')->style->fontSize);
    }

    public function testKeywordsAreCaseInsensitiveAndMediumIsTheInitialValue(): void
    {
        $document = $this->compile('<div id="parent"><p id="upper">A</p><p id="initial">B</p></div>', '#parent{font-size:30pt}#upper{font-size: X-Large }#initial{font-size:initial}');
        self::assertSame(18.0, $this->find($document->root, 'upper')->style->fontSize);
        self::assertSame(self::TABLE['medium'], $this->find($document->root, 'initial')->style->fontSize);
    }

    /** @return iterable<string,array{string}> */
    public static function parentSizes(): iterable
    {
        foreach ([...array_keys(self::TABLE), '6px', '9.5px', '13px', '15px', '16px', '21px', '32px', '100px', '7pt', '150%', '2em'] as $size) {
            yield $size => [$size];
        }
    }

    #[DataProvider('parentSizes')]
    public function testRelativeKeywordsScaleTheComputedParentSize(string $parentSize): void
    {
        $document = $this->compile('<div id="parent"><p id="larger">A</p><p id="smaller">B</p></div>', '#parent{font-size:' . $parentSize . '}#larger{font-size:larger}#smaller{font-size:smaller}');
        $parent = $this->find($document->root, 'parent')->style->fontSize;
        self::assertSame($parent * 1.2, $this->find($document->root, 'larger')->style->fontSize);
        self::assertSame($parent / 1.2, $this->find($document->root, 'smaller')->style->fontSize);
    }

    public function testTheCss1ExampleFromMediumIsAKnownDeviation(): void
    {
        $document = $this->compile('<div id="parent"><p id="subject">A</p></div>', '#parent{font-size:medium}#subject{font-size:larger}');
        $subject = $this->find($document->root, 'subject')->style->fontSize;
        self::assertEqualsWithDelta(14.4, $subject, 1e-12);
        self::assertNotSame(self::TABLE['large'], $subject, 'Both browser witnesses scale by 1.2; the table step of CSS1 5.2.6 is intentionally not used.');
    }

    public function testDescendantsInheritTheComputedSizeNotTheKeyword(): void
    {
        $document = $this->compile(
            '<div id="a"><div id="b"><p id="c">C</p><p id="d">D</p><p id="e">E</p></div></div>',
            '#a{font-size:20pt}#b{font-size:larger}#d{font-size:larger}#e{font-size:inherit}',
        );
        self::assertSame(24.0, $this->find($document->root, 'b')->style->fontSize);
        self::assertSame(24.0, $this->find($document->root, 'c')->style->fontSize);
        self::assertSame(24.0 * 1.2, $this->find($document->root, 'd')->style->fontSize);
        self::assertSame(24.0, $this->find($document->root, 'e')->style->fontSize);
    }

    public function testOtherPropertiesUseTheComputedKeywordSizeForEm(): void
    {
        $document = $this->compile('<p id="subject">A</p>', '#subject{font-size:x-large;margin-left:1em;line-height:1.5;text-indent:2em}');
        $style = $this->find($document->root, 'subject')->style;
        self::assertSame(18.0, $style->margin->left);
        self::assertSame(27.0, $style->lineHeight);
        self::assertSame(36.0, $style->textIndent->resolve(100));
    }

    public function testElementDefaultsStillScaleFromAKeywordParent(): void
    {
        $document = $this->compile('<div id="parent"><h1 id="heading">H</h1></div>', '#parent{font-size:small}');
        self::assertSame(19.5, $this->find($document->root, 'heading')->style->fontSize);
    }

    public function testRepeatedSmallerStaysFiniteWithoutASerializedRoundTrip(): void
    {
        // Below 1e-4 points PHP prints an exponent, which the length grammar rightly refuses.
        $html = str_repeat('<div class="s">', 64) . '<p id="leaf"><span id="inherited">x</span></p>' . str_repeat('</div>', 64);
        $diagnostics = new Diagnostics();
        $document = $this->compile('<div id="top">' . $html . '</div>', '#top{font-size:xx-small}.s{font-size:smaller}', $this->options(new ConversionLimits(maxDepth: 96)), $diagnostics);
        $leaf = $this->find($document->root, 'leaf')->style->fontSize;
        self::assertSame([], $diagnostics->all());
        self::assertGreaterThan(0.0, $leaf);
        self::assertLessThan(0.0001, $leaf);
        self::assertStringContainsString('E-', (string) $leaf);
        self::assertEqualsWithDelta(6.75 / 1.2 ** 64, $leaf, 1e-18);
        self::assertSame($leaf, $this->find($document->root, 'inherited')->style->fontSize);
    }

    public function testRelativeGrowthBeyondTheSupportedSizeIsRejected(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<div id="parent"><p>A</p></div>', '#parent{font-size:900pt}p{font-size:larger}');
    }

    /** @return iterable<string,array{string,float,float,int,bool,string}> */
    public static function shorthands(): iterable
    {
        yield 'CSS1 5.2.7 third example' => ['x-large/110% "new century schoolbook", serif', 18.0, 19.8, 400, false, '"new century schoolbook", serif'];
        yield 'CSS1 5.2.7 fourth example' => ['bold italic large Palatino, serif', 13.5, 18.0, 700, true, '"Palatino", serif'];
        yield 'relative keyword' => ['larger serif', 24.0, 32.0, 400, false, 'serif'];
        yield 'relative keyword with unitless line height' => ['italic smaller/2 sans-serif', 20.0 / 1.2, 2 * 20.0 / 1.2, 400, true, 'sans-serif'];
        yield 'medium keyword' => ['MEDIUM serif', 12.0, 16.0, 400, false, 'serif'];
    }

    #[DataProvider('shorthands')]
    public function testFontShorthandAcceptsSizeKeywords(string $value, float $size, float $lineHeight, int $weight, bool $italic, string $family): void
    {
        $document = $this->compile('<div id="parent"><p id="subject">A</p></div>', '#parent{font-size:20pt;line-height:50pt;font-weight:bold;font-style:italic}#subject{font:' . $value . '}');
        $style = $this->find($document->root, 'subject')->style;
        self::assertEqualsWithDelta($size, $style->fontSize, 1e-12);
        self::assertEqualsWithDelta($lineHeight, $style->lineHeight, 1e-12);
        self::assertSame($weight, $style->fontWeight);
        self::assertSame($italic, $style->italic);
        self::assertSame($family, $style->fontFamily->css());
    }

    public function testShorthandExpansionKeepsTheKeywordForTheCascade(): void
    {
        $expanded = (new Declarations(new Diagnostics()))->expand('font', 'bold X-Small/1.5 serif');
        self::assertSame('x-small', $expanded['font-size']);
        self::assertSame('1.5', $expanded['line-height']);
        self::assertSame('bold', $expanded['font-weight']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidSizes(): iterable
    {
        foreach (['xxx-large', 'largest', 'huge', 'medium large', '-larger', 'larger 2', 'x large', 'normal', 'bold', '0', '0pt', '-2pt'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidSizes')]
    public function testValuesOutsideTheCss1GrammarAreRejected(string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<p>A</p>', 'p{font-size:' . $value . '}');
    }

    public function testInvalidKeywordDoesNotEraseAnEarlierValidDeclarationInReportMode(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="subject">A</p>', '#subject{font-size:large;font-size:huge;font:italic xx-large serif;font:bold gigantic serif}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        $style = $this->find($document->root, 'subject')->style;
        self::assertSame(24.0, $style->fontSize);
        self::assertTrue($style->italic);
        self::assertSame(400, $style->fontWeight);
        self::assertCount(2, $diagnostics->all());
    }

    public function testResolverSeparatesKeywordsFromLengths(): void
    {
        $sizes = new FontSizes();
        self::assertNull($sizes->resolve('12pt', 10));
        self::assertNull($sizes->resolve('120%', 10));
        self::assertFalse($sizes->isKeyword('xxx-large'));
        self::assertTrue($sizes->isKeyword(" Smaller\n"));
        self::assertSame(12.0, $sizes->resolve('larger', 10));
        self::assertSame(1.2, FontSizes::RATIO);
        $this->expectException(\InvalidArgumentException::class);
        $sizes->resolve('larger', 0);
    }
}
