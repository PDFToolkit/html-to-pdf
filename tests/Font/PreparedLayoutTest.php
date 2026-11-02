<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\Fonts\OpenType\TextLayout;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontLimits;
use PDFToolkit\OpenType\Exception\MalformedFontException;
use PDFToolkit\OpenType\Substitution\FeatureSelection;
use PDFToolkit\OpenType\Value\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreparedLayoutTest extends TestCase
{
    #[DataProvider('fonts')]
    public function testPreparedTablesPreserveReferenceGlyphsPositionsAndUnicode(string $path): void
    {
        $limits = new FontLimits();
        $face = new FontFace($this->bytes($path), $limits);
        $reference = new TextLayout($face->program->font, $limits->readingLimits());
        $features = new FeatureSelection(new Tag('latn'), features: [new Tag('liga'), new Tag('kern'), new Tag('mark')]);
        foreach (['', 'AVATAR', 'office ffi fi', "A\u{0301} e\u{0308} a\u{0323}", 'été Ελληνικά Кириллица', 'office ffi fi'] as $text) {
            $expected = $reference->create(new UnicodeText($text), [$features], [$features]);
            $actual = $face->shape($text, 12)->text;
            self::assertEquals($expected->layout, $actual->layout, $text);
            self::assertEquals($expected->glyphs, $actual->glyphs, $text);
            self::assertSame($expected->source->utf8, $actual->source->utf8);
        }
    }

    public function testMissingGlyphKeepsReferenceDiagnostic(): void
    {
        $face = new FontFace($this->bytes('tests/Fixtures/fonts/NotoSans-Regular.ttf'));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('No glyph for U+1F600');
        $face->shape("\u{1F600}", 12);
    }

    public function testInputBudgetStillAppliesAfterSuccessfulRun(): void
    {
        $face = new FontFace($this->bytes('tests/Fixtures/fonts/NotoSans-Regular.ttf'), new FontLimits(maxGlyphs: 2));
        self::assertGreaterThan(0, $face->measure('AV', 12));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('glyph budget');
        $face->shape('AVA', 12);
    }

    public function testOperationBudgetRemainsFreshAndEnforcedPerRun(): void
    {
        $face = new FontFace($this->bytes('tests/Fixtures/fonts/NotoSans-Regular.ttf'), new FontLimits(maxSubstitutionOperations: 1));
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $face->shape('office', 12);
                self::fail('The substitution operation budget must reject this run.');
            } catch (MalformedFontException $error) {
                self::assertStringContainsString('limit', $error->getMessage());
            }
        }
    }

    public static function fonts(): iterable
    {
        yield 'Noto' => ['tests/Fixtures/fonts/NotoSans-Regular.ttf'];
        yield 'Lato' => ['examples/assets/fonts/Lato-Regular.ttf'];
    }

    private function bytes(string $path): string
    {
        $bytes = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($bytes);

        return $bytes;
    }
}
