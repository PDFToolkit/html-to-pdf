<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Font\FontStyle;
use PDF\HtmlToPdf\Style\FontSlant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The caller's font database of CSS1 5.2.1: explicit labels, no duplicate, bounded. */
final class FamilyFaceTest extends TestCase
{
    private static ?FontFace $noto = null;
    private static ?FontFace $lato = null;

    public static function setUpBeforeClass(): void
    {
        self::$noto = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
        self::$lato = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'));
    }

    /** Prepared layout tables are large: release them with the class. */
    public static function tearDownAfterClass(): void
    {
        self::$noto = null;
        self::$lato = null;
    }

    public function testNamedSlotsAreLabeledByPosition(): void
    {
        $set = new FontSet(self::$noto, self::$lato, self::$noto, self::$lato);
        self::assertSame(['normal:normal:400', 'normal:normal:700', 'italic:normal:400', 'italic:normal:700'], array_map(static fn(FamilyFace $face): string => $face->key(), $set->faces));
        self::assertSame([self::$noto, self::$lato, self::$noto, self::$lato], array_map(static fn(FamilyFace $face): FontFace => $face->face, $set->faces));
        self::assertSame(self::$lato, $set->face(FontStyle::BoldItalic));
    }

    public function testASingleFaceFamilyHasOneNormalFace(): void
    {
        $set = new FontSet(self::$noto);
        self::assertCount(1, $set->faces);
        self::assertSame(400, $set->faces[0]->weight);
        self::assertSame(FontSlant::Normal, $set->faces[0]->slant);
        self::assertFalse($set->faces[0]->smallCaps);
    }

    public function testAdditionalFacesKeepTheirExplicitLabelsAfterTheSlots(): void
    {
        $light = new FamilyFace(self::$lato, 300);
        $oblique = new FamilyFace(self::$lato, 400, FontSlant::Oblique);
        $smallCaps = new FamilyFace(self::$lato, 400, smallCaps: true);
        $set = new FontSet(self::$noto, bold: self::$noto, faces: [$light, $oblique, $smallCaps]);
        self::assertSame([$light, $oblique, $smallCaps], array_slice($set->faces, 2));
        self::assertSame(['normal:normal:400', 'normal:normal:700', 'normal:normal:300', 'oblique:normal:400', 'normal:small-caps:400'], array_map(static fn(FamilyFace $face): string => $face->key(), $set->faces));
    }

    /** @return iterable<string,array{int}> */
    public static function invalidWeights(): iterable
    {
        foreach ([0, 50, 99, 150, 450, 901, 1000, -400] as $weight) {
            yield (string) $weight => [$weight];
        }
    }

    #[DataProvider('invalidWeights')]
    public function testFaceWeightsAreTheNineCssSteps(int $weight): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FamilyFace(self::$noto, $weight);
    }

    public function testEveryCssStepIsAValidLabel(): void
    {
        foreach (range(100, 900, 100) as $weight) {
            self::assertSame($weight, (new FamilyFace(self::$noto, $weight))->weight);
        }
    }

    public function testASlotAndAnAdditionalFaceCannotShareTheSameLabels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('normal:normal:700');
        new FontSet(self::$noto, bold: self::$lato, faces: [new FamilyFace(self::$noto, 700)]);
    }

    public function testTwoAdditionalFacesCannotShareTheSameLabels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontSet(self::$noto, faces: [new FamilyFace(self::$lato, 300, FontSlant::Oblique), new FamilyFace(self::$noto, 300, FontSlant::Oblique)]);
    }

    public function testTheSameFileMayCarrySeveralLabels(): void
    {
        $set = new FontSet(self::$noto, faces: [new FamilyFace(self::$noto, 500), new FamilyFace(self::$noto, 400, FontSlant::Oblique)]);
        self::assertCount(3, $set->faces);
    }

    public function testAdditionalFacesMustBeAnOrderedListOfFamilyFaces(): void
    {
        try {
            new FontSet(self::$noto, faces: ['light' => new FamilyFace(self::$lato, 300)]);
            self::fail('A keyed array must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('ordered list', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        new FontSet(self::$noto, faces: [self::$lato]);
    }

    public function testDistinctLabelsBoundAFamilyToFiftyFourFaces(): void
    {
        $faces = [];
        foreach ([FontSlant::Normal, FontSlant::Italic, FontSlant::Oblique] as $slant) {
            foreach ([false, true] as $smallCaps) {
                foreach (range(100, 900, 100) as $weight) {
                    if (!($slant === FontSlant::Normal && !$smallCaps && $weight === 400)) {
                        $faces[] = new FamilyFace(self::$noto, $weight, $slant, $smallCaps);
                    }
                }
            }
        }
        self::assertCount(53, $faces);
        self::assertCount(54, (new FontSet(self::$noto, faces: $faces))->faces);
        $this->expectException(\InvalidArgumentException::class);
        new FontSet(self::$noto, faces: [...$faces, new FamilyFace(self::$lato, 900, FontSlant::Oblique, true)]);
    }
}
