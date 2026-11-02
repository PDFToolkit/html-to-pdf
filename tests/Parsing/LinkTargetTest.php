<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Parsing;

use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Parsing\LinkTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkTargetTest extends TestCase
{
    /** @return iterable<string,array{string,?string,string}> */
    public static function targets(): iterable
    {
        yield 'absolute with fragment' => ['https://example.test/a#b', null, 'https://example.test/a#b'];
        yield 'relative parent' => ['../a#b', 'https://example.test/dir/source.htm', 'https://example.test/a#b'];
        yield 'network reference' => ['//other.test/a', 'https://example.test/x', 'https://other.test/a'];
        yield 'query preserves reference fragment' => ['?x=1#part', 'https://example.test/a?old#unused', 'https://example.test/a?x=1#part'];
        yield 'mailto' => ['mailto:reader@example.test?subject=PDF', null, 'mailto:reader@example.test?subject=PDF'];
        yield 'empty reference uses explicit base' => ['', 'https://example.test/a#unused', 'https://example.test/a'];
    }

    #[DataProvider('targets')]
    public function testPureExternalTargetResolution(string $reference, ?string $base, string $expected): void
    {
        self::assertSame($expected, (new LinkTarget())->resolve($reference, $base));
    }

    /** @return iterable<string,array{string,?string}> */
    public static function unsupported(): iterable
    {
        yield 'relative without base' => ['a', null];
        yield 'fragment without base' => ['#a', null];
        yield 'executable scheme' => ['javascript:alert(1)', 'https://example.test/x'];
        yield 'data' => ['data:text/html,test', null];
        yield 'file base' => ['a', 'file:///tmp/x'];
        yield 'host absent' => ['https:///a', null];
        yield 'backslash' => ['https://example.test\\a', null];
        yield 'embedded newline' => ["https://example.test/\npath", null];
        yield 'non ASCII' => ['https://example.test/été', null];
        yield 'non ASCII base' => ['a', 'https://example.test/été/x'];
        yield 'base backslash' => ['a', 'https://example.test\\path/x'];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedTargetsRemainExplicit(string $reference, ?string $base): void
    {
        $this->expectException(ConversionException::class);
        (new LinkTarget())->resolve($reference, $base);
    }
}
