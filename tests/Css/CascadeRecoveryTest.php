<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;

final class CascadeRecoveryTest extends CompilerTestCase
{
    public function testReportRecoversAdjacentDeclarationsAndFollowingRules(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="x">X</p>', 'p{color:red;broken;background-color:blue}p{font-size:20pt}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(1.0, $style->background?->blue);
        self::assertSame(20.0, $style->fontSize);
        self::assertContains('css.syntax', array_map(static fn($item) => $item->code, $diagnostics->all()));
    }

    public function testRejectRemainsStrictForMalformedDeclarations(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<p>X</p>', 'p{color:red;broken;background-color:blue}');
    }

    public function testInlineRecoveryUsesTheOriginalWrappedSourcePositions(): void
    {
        $document = $this->compile('<p id="x" style="color:red;broken;font-size:20pt">X</p>', options: $this->options(policy: UnsupportedPolicy::Report));
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(20.0, $style->fontSize);
    }

    public function testOriginalValuesSurviveUnicodeCommentsAndQuotedDelimiters(): void
    {
        $css = "/* é 😀 */ p{unknown: 'x; } !important'; color/* : } */: rgb(-20,260,0) /* ; } */ !important; color:blue}";
        $document = $this->compile('<p id="x">X</p>', $css, $this->options(policy: UnsupportedPolicy::Report));
        $color = $this->find($document->root, 'x')->style->color;
        self::assertSame([0.0, 1.0, 0.0], [$color->red, $color->green, $color->blue]);
    }

    public function testOriginalInvalidColorDoesNotBecomeValidThroughAstSerialization(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{color:red;color:rgb(0..1,0,0)}', $this->options(policy: UnsupportedPolicy::Report));
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testNonCssVerticalTabWhitespaceCannotMakeAnInvalidValueValid(): void
    {
        $document = $this->compile('<p id="x">X</p>', "p{color:red;color:\x0bblue; color:blue!\x0bimportant}", $this->options(policy: UnsupportedPolicy::Report));
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testDeclarationDiagnosticIsNotRepeatedForEverySelectorInItsList(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report, 1);
        $document = $this->compile('<p id="x">X</p>', 'p,#x,body p{color:bogus;font-size:20pt}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertCount(1, $diagnostics->all());
        self::assertSame(20.0, $this->find($document->root, 'x')->style->fontSize);
    }
}
