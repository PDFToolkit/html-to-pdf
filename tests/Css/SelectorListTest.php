<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class SelectorListTest extends CompilerTestCase
{
    /** @return iterable<string,array{string}> */
    public static function invalidLists(): iterable
    {
        yield 'unknown last' => ['p,:unknown'];
        yield 'unknown first' => [':unknown,p'];
        yield 'dynamic last' => ['p,a:hover'];
        yield 'nested dynamic' => ['p,:is(.x,a:hover)'];
        yield 'pseudo element' => ['p,p::first-letter'];
        yield 'invalid token' => ['p,???'];
        yield 'missing final selector' => ['p,'];
        yield 'invalid attribute' => ['p,[x=]'];
    }

    #[DataProvider('invalidLists')]
    public function testUnsupportedSelectorRejectsTheWholeList(string $selectors): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{color:blue}' . $selectors . '{color:red}', $this->options(policy: UnsupportedPolicy::Report));
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->blue);
    }

    /** @return iterable<string,array{string,string}> */
    public static function escapedSelectors(): iterable
    {
        yield 'Unicode hex escape' => ['<p id="café">X</p>', '#caf\\e9'];
        yield 'escaped punctuation' => ['<p id="café" class="a:hover">X</p>', '.a\\:hover'];
        yield 'six digit escape' => ['<p id="café">X</p>', '#caf\\0000e9'];
        yield 'leading digit' => ['<p id="café" class="12a">X</p>', '.\\31 2a'];
        yield 'escaped attribute text' => ['<p id="café" title="a:hover">X</p>', '[title="a\\:hover"]'];
    }

    #[DataProvider('escapedSelectors')]
    public function testEscapedIdentifiersRemainStaticSelectors(string $html, string $selector): void
    {
        $document = $this->compile($html, $selector . '{color:red}');
        self::assertSame(1.0, $this->find($document->root, 'café')->style->color->red);
    }

    public function testCombinatorTreeDepthIsBoundedBeforeRecursiveTranslation(): void
    {
        $this->expectException(LimitExceeded::class);
        $this->compile('<p>X</p>', 'html body p p p p p p{color:red}', $this->options(new ConversionLimits(maxDepth: 5)));
    }
}
