<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Layout;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\Layout\ListCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListCounterTest extends TestCase
{
    public function testSignedCountersAndExplicitValuesRemainExactIntegers(): void
    {
        $counter = new ListCounter(' -0002 ');
        self::assertSame(-2, $counter->next());
        self::assertSame(-1, $counter->next());
        self::assertSame(0, $counter->next());
        self::assertSame(7, $counter->next('+007'));
        self::assertSame(8, $counter->next());
        self::assertSame(PHP_INT_MIN, $counter->next((string) PHP_INT_MIN));
        self::assertSame(PHP_INT_MIN + 1, $counter->next());
    }

    public function testTheMaximumIntegerCanBeUsedAndThenResetExplicitly(): void
    {
        $counter = new ListCounter((string) PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX, $counter->next());
        self::assertSame(3, $counter->next('3'));
        self::assertSame(4, $counter->next());
    }

    public function testTheMaximumExplicitValueCannotOverflowOnTheNextItem(): void
    {
        $counter = new ListCounter();
        self::assertSame(PHP_INT_MAX, $counter->next((string) PHP_INT_MAX));
        $this->expectException(UnsupportedFeature::class);
        $counter->next();
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedNumbers(): iterable
    {
        yield 'positive overflow' => [(string) PHP_INT_MAX . '0'];
        yield 'negative overflow' => [(string) PHP_INT_MIN . '0'];
        yield 'scientific notation' => ['1e3'];
        yield 'fraction' => ['1.5'];
        yield 'non-numeric suffix' => ['2items'];
        yield 'empty value' => [''];
    }

    #[DataProvider('unsupportedNumbers')]
    public function testUnsupportedStartIsRejectedInsteadOfCoerced(string $start): void
    {
        $this->expectException(UnsupportedFeature::class);
        new ListCounter($start);
    }

    #[DataProvider('unsupportedNumbers')]
    public function testUnsupportedItemValueIsRejectedInsteadOfCoerced(string $value): void
    {
        $counter = new ListCounter();
        $this->expectException(UnsupportedFeature::class);
        $counter->next($value);
    }
}
