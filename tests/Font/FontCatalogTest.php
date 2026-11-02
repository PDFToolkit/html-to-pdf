<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\HtmlToPdf\Css\FontFamilyParser;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\Font\FontStyle;
use PHPUnit\Framework\TestCase;

final class FontCatalogTest extends TestCase
{
    private static FontFace $noto;
    private static FontFace $lato;

    public static function setUpBeforeClass(): void
    {
        self::$noto = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
        self::$lato = new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf'));
    }

    public function testExistingApiKeepsItsDefaultFace(): void
    {
        $set = new FontSet(self::$noto);
        self::assertSame(self::$noto, $set->face(FontStyle::Regular));
    }

    public function testOrderedCaseInsensitiveFamilyMatchingAndExplicitGenericFallback(): void
    {
        $catalog = new FontCatalog(['Example Font' => new FontSet(self::$lato)], ['sans-serif' => new FontSet(self::$noto)]);
        $set = new FontSet(self::$noto, catalog: $catalog);
        $parser = new FontFamilyParser();
        self::assertSame(self::$lato, $set->face(FontStyle::Regular, $parser->parse('Missing, "eXample fOnt", sans-serif')));
        self::assertSame(self::$noto, $set->face(FontStyle::Regular, $parser->parse('Verdana, sans-serif')));
    }

    public function testQuotedGenericNameDoesNotSelectTheGenericBinding(): void
    {
        $set = new FontSet(self::$noto, catalog: new FontCatalog(['sans-serif' => new FontSet(self::$lato)], ['sans-serif' => new FontSet(self::$noto)]));
        self::assertSame(self::$lato, $set->face(FontStyle::Regular, (new FontFamilyParser())->parse('"sans-serif"')));
        self::assertSame(self::$noto, $set->face(FontStyle::Regular, (new FontFamilyParser())->parse('sans-serif')));
    }

    public function testUnboundFamilyCannotSilentlyUseTheDefaultFont(): void
    {
        $this->expectException(UnsupportedFeature::class);
        (new FontSet(self::$noto))->face(FontStyle::Regular, (new FontFamilyParser())->parse('Verdana, sans-serif'));
    }

    public function testMissingVariantDoesNotSilentlyChangeFamilyOrSynthesize(): void
    {
        $set = new FontSet(self::$noto, catalog: new FontCatalog(['Example' => new FontSet(self::$lato)], ['serif' => new FontSet(self::$noto, self::$noto)]));
        $this->expectException(UnsupportedFeature::class);
        $set->face(FontStyle::Bold, (new FontFamilyParser())->parse('Example, serif'));
    }

    public function testCaseDuplicateBindingsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontCatalog(['Example' => new FontSet(self::$noto), 'EXAMPLE' => new FontSet(self::$lato)]);
    }

    public function testGenericBindingsRequireKnownGenericKeywords(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontCatalog(genericFamilies: ['Verdana' => new FontSet(self::$noto)]);
    }
}
