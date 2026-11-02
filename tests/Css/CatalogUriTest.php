<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Resource\CatalogUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogUriTest extends TestCase
{
    /** @return iterable<string,array{string,?string,string}> */
    public static function references(): iterable
    {
        yield 'relative catalog parent' => ['../color.css', 'styles/main.css', 'color.css'];
        yield 'absolute base parent' => ['../color.css', 'https://example.test/styles/main.css', 'https://example.test/color.css'];
        yield 'absolute reference' => ['https://other.test/a/../b.css', 'https://example.test/x.css', 'https://other.test/b.css'];
        yield 'network path' => ['//other.test/a.css', 'https://example.test/x.css', 'https://other.test/a.css'];
        yield 'query replacement' => ['?mode=print', 'https://example.test/x.css?mode=screen', 'https://example.test/x.css?mode=print'];
        yield 'fragment exclusion' => ['#fragment', 'styles/x.css?version=1', 'styles/x.css?version=1'];
        yield 'encoded dot is literal' => ['%2e%2e/x.css', 'styles/main.css', 'styles/%2e%2e/x.css'];
        yield 'double slash is significant' => ['a//b.css', null, 'a//b.css'];
        yield 'file URI remains only an identifier' => ['file:///tmp/x.css', null, 'file:///tmp/x.css'];
    }

    #[DataProvider('references')]
    public function testCatalogReferenceResolution(string $reference, ?string $base, string $expected): void
    {
        self::assertSame($expected, (new CatalogUri())->resolve($reference, $base));
    }

    public function testControlCharactersCannotEnterResourceIdentity(): void
    {
        $this->expectException(ConversionException::class);
        (new CatalogUri())->resolve("x.css\nother.css");
    }
}
