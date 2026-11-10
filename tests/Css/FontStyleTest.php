<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Style\ComputedStyle;
use PDF\HtmlToPdf\Style\FontSlant;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/** CSS1 5.2.3: normal, italic and oblique are three distinct computed values. */
final class FontStyleTest extends CompilerTestCase
{
    /** @return iterable<string,array{string,FontSlant,bool}> */
    public static function values(): iterable
    {
        yield 'normal' => ['normal', FontSlant::Normal, false];
        yield 'italic' => ['italic', FontSlant::Italic, true];
        yield 'oblique' => ['oblique', FontSlant::Oblique, false];
        yield 'case and spaces' => [' Oblique ', FontSlant::Oblique, false];
    }

    #[DataProvider('values')]
    public function testEachValueIsKeptAsSpecified(string $value, FontSlant $slant, bool $italic): void
    {
        $style = $this->find($this->compile('<div id="parent"><p id="subject">A</p></div>', '#parent{font-style:italic}#subject{font-style:' . $value . '}')->root, 'subject')->style;
        self::assertSame($slant, $style->fontSlant());
        self::assertSame($italic, $style->italic);
    }

    public function testStyleIsInheritedAndCanBeNeutralized(): void
    {
        // CSS1 5.2.3 example: emphasized text within an italic heading appears in a normal face.
        $document = $this->compile(
            '<h1 id="h1">Heading <em id="em">emphasis</em> <span id="span">span</span></h1><div id="oblique"><p id="child">A</p><p id="initial">B</p><em id="element">C</em></div>',
            'h1{font-style:italic}h1 em{font-style:normal}#oblique{font-style:oblique}#initial{font-style:initial}',
        );
        self::assertSame(FontSlant::Italic, $this->find($document->root, 'h1')->style->fontSlant());
        self::assertSame(FontSlant::Normal, $this->find($document->root, 'em')->style->fontSlant());
        self::assertSame(FontSlant::Italic, $this->find($document->root, 'span')->style->fontSlant());
        self::assertSame(FontSlant::Oblique, $this->find($document->root, 'child')->style->fontSlant());
        self::assertSame(FontSlant::Normal, $this->find($document->root, 'initial')->style->fontSlant());
        self::assertSame(FontSlant::Italic, $this->find($document->root, 'element')->style->fontSlant(), 'em is italic by default, whatever its parent');
    }

    public function testFontShorthandAcceptsObliqueAndResetsTheStyle(): void
    {
        $document = $this->compile('<div id="parent"><p id="a">A</p><p id="b">B</p><p id="c">C</p></div>', '#parent{font-style:oblique;font-size:20pt}#a{font:oblique 300 smaller serif}#b{font:12px serif}#c{font:bold Oblique 10px/2 serif}');
        $a = $this->find($document->root, 'a')->style;
        self::assertSame(FontSlant::Oblique, $a->fontSlant());
        self::assertSame(300, $a->fontWeight);
        self::assertEqualsWithDelta(20 / 1.2, $a->fontSize, 1e-12);
        self::assertSame(FontSlant::Normal, $this->find($document->root, 'b')->style->fontSlant());
        $c = $this->find($document->root, 'c')->style;
        self::assertSame(FontSlant::Oblique, $c->fontSlant());
        self::assertSame(700, $c->fontWeight);
        self::assertSame(15.0, $c->lineHeight);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidStyles(): iterable
    {
        foreach (['slanted', 'oblique 10deg', 'italic oblique', 'kursiv', 'bold', '400', 'none'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidStyles')]
    public function testValuesOutsideTheCss1GrammarAreRejected(string $value): void
    {
        $this->expectException(UnsupportedFeature::class);
        $this->compile('<p>A</p>', 'p{font-style:' . $value . '}');
    }

    public function testInvalidStyleDoesNotEraseAnEarlierObliqueInReportMode(): void
    {
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="subject">A</p>', '#subject{font-style:oblique;font-style:slanted}', $this->options(policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertSame(FontSlant::Oblique, $this->find($document->root, 'subject')->style->fontSlant());
        self::assertCount(1, $diagnostics->all());
    }

    public function testTheBooleanConstructorFieldStillDescribesItalic(): void
    {
        self::assertSame(FontSlant::Italic, (new ComputedStyle(italic: true))->fontSlant());
        self::assertSame(FontSlant::Normal, (new ComputedStyle())->fontSlant());
        self::assertSame(FontSlant::Oblique, (new ComputedStyle(italic: false, slant: FontSlant::Oblique))->fontSlant());
        self::assertSame(FontSlant::Normal, (new ComputedStyle(italic: true, slant: FontSlant::Normal))->fontSlant(), 'the explicit style takes precedence');
    }
}
