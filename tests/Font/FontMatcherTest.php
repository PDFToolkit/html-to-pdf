<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\HtmlToPdf\Css\FontFamilyParser;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontMatcher;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Style\FontSlant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CSS1 5.2.1 and 5.2.5 as revised by CSS 2.1 15.2 and 15.6, on labeled faces only. */
final class FontMatcherTest extends TestCase
{
    private static ?FontFace $noto = null;
    private static ?FontFace $lato = null;
    private static ?FontFace $latoBold = null;
    private static ?FontFace $latoItalic = null;

    public static function setUpBeforeClass(): void
    {
        self::$noto = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
        self::$lato = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'));
        self::$latoBold = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Bold.ttf'));
        self::$latoItalic = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Italic.ttf'));
    }

    /** Prepared layout tables are large: release them with the class. */
    public static function tearDownAfterClass(): void
    {
        self::$noto = null;
        self::$lato = null;
        self::$latoBold = null;
        self::$latoItalic = null;
    }

    /** @return iterable<string,array{list<int>,array<int,int>}> */
    public static function css1ExampleFamilies(): iterable
    {
        // CSS1 5.2.5: "Example1" Regular, Medium, Bold, Heavy and its filled holes.
        yield 'Example1' => [[500, 700, 800], [100 => 400, 200 => 400, 300 => 400, 400 => 400, 500 => 500, 600 => 700, 700 => 700, 800 => 800, 900 => 800]];
        // "Example2" Book, Medium, Bold, Heavy, Black.
        yield 'Example2' => [[500, 700, 800, 900], [100 => 400, 200 => 400, 300 => 400, 400 => 400, 500 => 500, 600 => 700, 700 => 700, 800 => 800, 900 => 900]];
        yield 'normal and bold only' => [[700], [100 => 400, 200 => 400, 300 => 400, 400 => 400, 500 => 400, 600 => 700, 700 => 700, 800 => 700, 900 => 700]];
        yield 'single face' => [[], [100 => 400, 400 => 400, 500 => 400, 600 => 400, 900 => 400]];
    }

    /**
     * @param list<int> $additional
     * @param array<int,int> $expected
     */
    #[DataProvider('css1ExampleFamilies')]
    public function testHolesAreFilledAsInTheSpecificationExamples(array $additional, array $expected): void
    {
        $family = new FontSet(self::$noto, faces: array_map(static fn(int $weight): FamilyFace => new FamilyFace(self::$noto, $weight), $additional));
        foreach ($expected as $desired => $weight) {
            self::assertSame($weight, (new FontMatcher())->match($family, FontSlant::Normal, $desired)?->weight, 'desired ' . $desired);
        }
    }

    public function testTheRuleIsNotNearestWeight(): void
    {
        $matcher = new FontMatcher();
        $three = $this->italics([300, 500, 800]);
        // A nearest-weight rule would answer 500 for 600 and 300 for 400.
        self::assertSame(800, $matcher->match($three, FontSlant::Italic, 600)?->weight);
        self::assertSame(500, $matcher->match($three, FontSlant::Italic, 400)?->weight);
        self::assertSame(300, $matcher->match($three, FontSlant::Italic, 100)?->weight);
        self::assertSame(800, $matcher->match($three, FontSlant::Italic, 900)?->weight);
        $two = $this->italics([200, 600]);
        // 500 tries 400, then lighter weights before darker ones: 200, although 600 is nearer.
        self::assertSame(200, $matcher->match($two, FontSlant::Italic, 500)?->weight);
        self::assertSame(200, $matcher->match($two, FontSlant::Italic, 400)?->weight);
        self::assertSame(200, $matcher->match($two, FontSlant::Italic, 300)?->weight);
        self::assertSame(600, $matcher->match($two, FontSlant::Italic, 700)?->weight);
        self::assertSame(200, $matcher->match($two, FontSlant::Italic, 100)?->weight);
    }

    public function testEverySubsetOfWeightsFollowsTheOrderPrintedInCss21(): void
    {
        $matcher = new FontMatcher();
        $steps = range(100, 900, 100);
        $checked = 0;
        for ($mask = 1; $mask < 512; ++$mask) {
            $available = array_values(array_filter($steps, static fn(int $weight): bool => ($mask & (1 << intdiv($weight, 100) - 1)) !== 0));
            $family = $this->italics($available);
            foreach ($steps as $desired) {
                $expected = null;
                foreach (self::printedOrder($desired) as $candidate) {
                    if (in_array($candidate, $available, true)) {
                        $expected = $candidate;
                        break;
                    }
                }
                self::assertSame($expected, $matcher->match($family, FontSlant::Italic, $desired)?->weight, 'desired ' . $desired . ' among ' . implode(',', $available));
                self::assertSame($expected, $matcher->weightOrder($desired, array_reverse($available))[0]);
                ++$checked;
            }
        }
        self::assertSame(511 * 9, $checked);
    }

    public function testADarkerRequestNeverSelectsALighterFace(): void
    {
        $matcher = new FontMatcher();
        for ($mask = 1; $mask < 512; ++$mask) {
            $family = $this->italics(array_values(array_filter(range(100, 900, 100), static fn(int $weight): bool => ($mask & (1 << intdiv($weight, 100) - 1)) !== 0)));
            $previous = 0;
            foreach (range(100, 900, 100) as $desired) {
                $weight = $matcher->match($family, FontSlant::Italic, $desired)->weight ?? 0;
                self::assertGreaterThanOrEqual($previous, $weight);
                $previous = $weight;
            }
        }
    }

    public function testNumbersBetweenTheStepsHaveADefinedMatch(): void
    {
        $matcher = new FontMatcher();
        $family = new FontSet(self::$noto, bold: self::$lato);
        // Above 700 nothing darker exists, so the lighter faces are tried in descending order: 700 first.
        foreach ([1 => 400, 350 => 400, 399 => 400, 450 => 400, 501 => 700, 550 => 700, 650 => 700, 701 => 700, 1000 => 700] as $desired => $weight) {
            self::assertSame($weight, $matcher->match($family, FontSlant::Normal, $desired)?->weight, 'desired ' . $desired);
        }
        self::assertSame([450, 500, 400, 300, 600], $matcher->weightOrder(450, [300, 400, 450, 500, 600]));
        self::assertSame([], $matcher->weightOrder(400, []));
    }

    public function testItalicPrefersItalicThenAcceptsOblique(): void
    {
        $matcher = new FontMatcher();
        $oblique = new FamilyFace(self::$lato, 400, FontSlant::Oblique);
        $both = new FontSet(self::$noto, italic: self::$latoItalic, faces: [$oblique]);
        self::assertSame(self::$latoItalic, $matcher->match($both, FontSlant::Italic, 400)?->face);
        self::assertSame($oblique, $matcher->match($both, FontSlant::Oblique, 400));
        $obliqueOnly = new FontSet(self::$noto, faces: [$oblique]);
        self::assertSame($oblique, $matcher->match($obliqueOnly, FontSlant::Italic, 400));
        self::assertSame(self::$noto, $matcher->match($obliqueOnly, FontSlant::Normal, 400)?->face);
    }

    public function testOtherStylesMatchExactlyOrFailForTheFamily(): void
    {
        $matcher = new FontMatcher();
        $italicOnly = new FontSet(self::$noto, italic: self::$latoItalic);
        self::assertNull($matcher->match($italicOnly, FontSlant::Oblique, 400), 'oblique is not satisfied by an italic face');
        self::assertNull($matcher->match(new FontSet(self::$noto), FontSlant::Italic, 400));
        self::assertNull($matcher->match(new FontSet(self::$noto), FontSlant::Oblique, 700));
        self::assertSame(self::$noto, $matcher->match($italicOnly, FontSlant::Normal, 900)?->face, 'normal never selects a slanted face');
    }

    public function testStyleIsTriedBeforeWeight(): void
    {
        $matcher = new FontMatcher();
        $family = new FontSet(self::$noto, bold: self::$latoBold, italic: self::$latoItalic);
        // The only italic face is 400: a bold italic request keeps the style and gives up the weight.
        self::assertSame(self::$latoItalic, $matcher->match($family, FontSlant::Italic, 700)?->face);
        self::assertSame(self::$latoBold, $matcher->match($family, FontSlant::Normal, 700)?->face);
    }

    public function testVariantPrefersItsLabelButNeverFails(): void
    {
        $matcher = new FontMatcher();
        $smallCaps = new FamilyFace(self::$lato, 400, smallCaps: true);
        $family = new FontSet(self::$noto, bold: self::$latoBold, faces: [$smallCaps]);
        self::assertSame($smallCaps, $matcher->match($family, FontSlant::Normal, 400, true));
        self::assertSame(self::$noto, $matcher->match($family, FontSlant::Normal, 400, false)?->face);
        // The variant is tried before the weight: the labeled small-caps face wins over the bold one.
        self::assertSame($smallCaps, $matcher->match($family, FontSlant::Normal, 700, true));
        // Without a small-caps font, the normal faces remain candidates for synthesis.
        self::assertSame(self::$latoBold, $matcher->match(new FontSet(self::$noto, bold: self::$latoBold), FontSlant::Normal, 700, true)?->face);
        // A style available only as small-caps stays selectable as normal (CSS 2.1 15.2).
        $obliqueSmallCaps = new FamilyFace(self::$lato, 400, FontSlant::Oblique, true);
        self::assertSame($obliqueSmallCaps, $matcher->match(new FontSet(self::$noto, faces: [$obliqueSmallCaps]), FontSlant::Oblique, 400, false));
    }

    public function testBestMatchInTheDefaultFamilyRelaxesTheStyleOnly(): void
    {
        $matcher = new FontMatcher();
        $plain = new FontSet(self::$noto, bold: self::$latoBold);
        self::assertSame(self::$latoBold, $matcher->best($plain, FontSlant::Italic, 700)->face);
        self::assertSame(self::$noto, $matcher->best($plain, FontSlant::Oblique, 300)->face);
        $italic = new FontSet(self::$noto, italic: self::$latoItalic);
        self::assertSame(self::$latoItalic, $matcher->best($italic, FontSlant::Oblique, 400)->face, 'an italic face is the closest slanted substitute for oblique');
        self::assertSame(self::$noto, $matcher->best($italic, FontSlant::Normal, 400)->face);
    }

    public function testChainListsMatchingFamiliesThenTheDefaultFamily(): void
    {
        $matcher = new FontMatcher();
        $default = new FontSet(self::$noto, catalog: new FontCatalog(
            ['Lato' => new FontSet(self::$lato, bold: self::$latoBold, italic: self::$latoItalic), 'Plain' => new FontSet(self::$latoBold)],
            ['sans-serif' => new FontSet(self::$lato)],
        ));
        $parse = static fn(string $families) => (new FontFamilyParser())->parse($families);

        $chain = $matcher->chain($default, $parse('Missing, Plain, Lato, sans-serif'), FontSlant::Normal, 400);
        self::assertSame([self::$latoBold, self::$lato, self::$noto], array_map(static fn(FamilyFace $face): FontFace => $face->face, $chain->faces));
        self::assertTrue($chain->listedFamilyMatched);
        self::assertFalse($chain->styleRelaxed);
        self::assertSame(self::$latoBold, $chain->primary()->face);

        // Step 3: a family without the requested style is skipped, not relaxed.
        $italic = $matcher->chain($default, $parse('Plain, Lato'), FontSlant::Italic, 400);
        self::assertSame([self::$latoItalic, self::$noto], array_map(static fn(FamilyFace $face): FontFace => $face->face, $italic->faces));
        self::assertTrue($italic->listedFamilyMatched);
        self::assertTrue($italic->styleRelaxed);
    }

    public function testChainFallsBackToTheDefaultFamilyAlone(): void
    {
        $matcher = new FontMatcher();
        $default = new FontSet(self::$noto, catalog: new FontCatalog(['Plain' => new FontSet(self::$lato)]));
        $unbound = $matcher->chain($default, (new FontFamilyParser())->parse('Verdana, serif'), FontSlant::Normal, 700);
        self::assertSame([self::$noto], array_map(static fn(FamilyFace $face): FontFace => $face->face, $unbound->faces));
        self::assertFalse($unbound->listedFamilyMatched);
        self::assertFalse($unbound->styleRelaxed);

        $styleFails = $matcher->chain($default, (new FontFamilyParser())->parse('Plain'), FontSlant::Oblique, 400);
        self::assertSame([self::$noto], array_map(static fn(FamilyFace $face): FontFace => $face->face, $styleFails->faces));
        self::assertFalse($styleFails->listedFamilyMatched);
        self::assertTrue($styleFails->styleRelaxed);

        $empty = $matcher->chain($default, (new FontFamilyParser())->parse('Plain'), FontSlant::Normal, 400);
        self::assertSame([self::$lato, self::$noto], array_map(static fn(FamilyFace $face): FontFace => $face->face, $empty->faces));
    }

    public function testChainKeepsOneCandidatePerFontProgram(): void
    {
        $matcher = new FontMatcher();
        $default = new FontSet(self::$noto, catalog: new FontCatalog(['Same' => new FontSet(self::$noto), 'Other' => new FontSet(self::$lato)], ['serif' => new FontSet(self::$lato)]));
        $chain = $matcher->chain($default, (new FontFamilyParser())->parse('Same, Other, serif, Same'), FontSlant::Normal, 400);
        self::assertSame([self::$noto, self::$lato], array_map(static fn(FamilyFace $face): FontFace => $face->face, $chain->faces));
        self::assertTrue($chain->listedFamilyMatched);
    }

    /** @param list<int> $weights */
    private function italics(array $weights): FontSet
    {
        return new FontSet(self::$noto, faces: array_map(static fn(int $weight): FamilyFace => new FamilyFace(self::$noto, $weight, FontSlant::Italic), $weights));
    }

    /**
     * The four sentences of CSS 2.1 15.6 written out as candidate lists, independently of the matcher.
     *
     * @return list<int>
     */
    private static function printedOrder(int $desired): array
    {
        $lighter = [];
        for ($weight = $desired - 100; $weight >= 100; $weight -= 100) {
            $lighter[] = $weight;
        }
        $darker = [];
        for ($weight = $desired + 100; $weight <= 900; $weight += 100) {
            $darker[] = $weight;
        }

        return match (true) {
            $desired < 400 => [$desired, ...$lighter, ...$darker],
            $desired > 500 => [$desired, ...$darker, ...$lighter],
            $desired === 400 => [400, 500, 300, 200, 100, 600, 700, 800, 900],
            default => [500, 400, 300, 200, 100, 600, 700, 800, 900],
        };
    }
}
