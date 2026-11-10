<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Font;

use PDF\HtmlToPdf\Font\FaceChain;
use PDF\HtmlToPdf\Font\FamilyFace;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\TextItemizer;
use PDF\HtmlToPdf\Font\TextSegment;
use PHPUnit\Framework\TestCase;

/** CSS1 5.2.1 step 4: a character without a glyph is taken from the next candidate face. */
final class TextItemizerTest extends TestCase
{
    private static ?FamilyFace $lato = null;
    private static ?FamilyFace $noto = null;

    public static function setUpBeforeClass(): void
    {
        self::$lato = new FamilyFace(new FontFace(file_get_contents(__DIR__ . '/../../examples/assets/fonts/Lato-Regular.ttf')));
        self::$noto = new FamilyFace(new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf')));
    }

    /** Prepared layout tables are large: release them with the class. */
    public static function tearDownAfterClass(): void
    {
        self::$lato = self::$noto = null;
    }

    public function testFixtureCoverageMatchesTheAssumptionsOfTheseTests(): void
    {
        $lato = self::$lato->face;
        $noto = self::$noto->face;
        foreach ([0x41, 0x20, 0xE9, 0x301, 0x41A] as $both) {
            self::assertTrue($lato->hasGlyph($both) && $noto->hasGlyph($both), sprintf('U+%04X is in both fonts', $both));
        }
        foreach ([0x211D, 0x377, 0x487, 0x2011] as $notoOnly) {
            self::assertTrue($noto->hasGlyph($notoOnly) && !$lato->hasGlyph($notoOnly), sprintf('U+%04X is in Noto Sans only', $notoOnly));
        }
        foreach ([0x2192, 0x25CB, 0x25AA] as $latoOnly) {
            self::assertTrue($lato->hasGlyph($latoOnly) && !$noto->hasGlyph($latoOnly), sprintf('U+%04X is in Lato only', $latoOnly));
        }
        self::assertFalse($lato->hasGlyph(0x1F600) || $noto->hasGlyph(0x1F600));
    }

    public function testTextCoveredByTheFirstFaceStaysOneRun(): void
    {
        $segments = (new TextItemizer())->itemize("Plain text, été, Кириллица e\u{0301}", $this->chain());
        self::assertCount(1, $segments);
        self::assertSame(self::$lato, $segments[0]->face);
        self::assertSame("Plain text, été, Кириллица e\u{0301}", $segments[0]->text);
        self::assertFalse($segments[0]->split);
    }

    public function testASingleCandidateIsNeverProbed(): void
    {
        $segments = (new TextItemizer())->itemize("A\u{211D}\u{1F600}", new FaceChain([self::$lato], true, false));
        self::assertEquals([new TextSegment(self::$lato, "A\u{211D}\u{1F600}")], $segments);
    }

    public function testMissingCharactersMoveToTheNextFaceAndComeBack(): void
    {
        $segments = (new TextItemizer())->itemize("Set \u{211D}\u{0377} and \u{2011}.", $this->chain());
        self::assertSame(
            [['lato', 'Set '], ['noto', "\u{211D}\u{0377}"], ['lato', ' and '], ['noto', "\u{2011}"], ['lato', '.']],
            $this->describe($segments),
        );
        self::assertSame([false, false, false, false, false], array_map(static fn(TextSegment $segment): bool => $segment->split, $segments));
    }

    public function testFaceOrderIsThePriorityOrder(): void
    {
        $text = "A\u{2192}B";
        self::assertSame([['lato', $text]], $this->describe((new TextItemizer())->itemize($text, $this->chain())));
        self::assertSame([['noto', 'A'], ['lato', "\u{2192}"], ['noto', 'B']], $this->describe((new TextItemizer())->itemize($text, new FaceChain([self::$noto, self::$lato], true, false))));
    }

    public function testAClusterStaysInOneFaceWhenOneFaceHasAllOfIt(): void
    {
        // Lato has the Cyrillic letter but not the combining mark; Noto Sans has both.
        $segments = (new TextItemizer())->itemize("a\u{041A}\u{0487}b", $this->chain());
        self::assertSame([['lato', 'a'], ['noto', "\u{041A}\u{0487}"], ['lato', 'b']], $this->describe($segments));
        self::assertFalse($segments[1]->split);
    }

    public function testAClusterNoFaceCoversIsDividedByCharacter(): void
    {
        // The arrow exists in Lato only and the mark in Noto Sans only.
        $segments = (new TextItemizer())->itemize("x\u{2192}\u{0487}y", $this->chain());
        self::assertSame([['lato', "x\u{2192}"], ['noto', "\u{0487}"], ['lato', 'y']], $this->describe($segments));
        self::assertSame([true, true, false], array_map(static fn(TextSegment $segment): bool => $segment->split, $segments));
    }

    public function testACharacterAbsentEverywhereStaysWithTheFirstFace(): void
    {
        $segments = (new TextItemizer())->itemize("A\u{1F600}\u{211D}", $this->chain());
        self::assertSame([['lato', "A\u{1F600}"], ['noto', "\u{211D}"]], $this->describe($segments));
        self::assertFalse($segments[0]->split, 'an undivided cluster is not a split');
    }

    public function testSegmentsAlwaysConcatenateToTheInput(): void
    {
        $alphabet = ['A', ' ', 'é', "\u{0301}", "\u{0487}", "\u{211D}", "\u{0377}", "\u{2192}", "\u{25CB}", "\u{1F600}", "\u{00A0}", "\u{2011}", 'К'];
        $itemizer = new TextItemizer();
        $state = 12345;
        for ($round = 0; $round < 300; ++$round) {
            $text = '';
            for ($index = 0, $length = $state % 12; $index < $length; ++$index) {
                $state = ($state * 1103515245 + 12345) % 2147483648;
                $text .= $alphabet[$state % count($alphabet)];
            }
            $state = ($state * 1103515245 + 12345) % 2147483648;
            foreach ([$this->chain(), new FaceChain([self::$noto, self::$lato], true, false)] as $chain) {
                $segments = $itemizer->itemize($text, $chain);
                self::assertSame($text, implode('', array_map(static fn(TextSegment $segment): string => $segment->text, $segments)));
                foreach ($segments as $index => $segment) {
                    self::assertTrue($index === 0 || $segments[$index - 1]->face !== $segment->face, 'runs are maximal');
                    self::assertTrue($text === '' || $segment->text !== '');
                }
            }
        }
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        @(new TextItemizer())->itemize("\xFF", $this->chain());
    }

    private function chain(): FaceChain
    {
        return new FaceChain([self::$lato, self::$noto], true, false);
    }

    /**
     * @param list<TextSegment> $segments
     * @return list<array{string,string}>
     */
    private function describe(array $segments): array
    {
        return array_map(static fn(TextSegment $segment): array => [$segment->face === self::$lato ? 'lato' : 'noto', $segment->text], $segments);
    }
}
