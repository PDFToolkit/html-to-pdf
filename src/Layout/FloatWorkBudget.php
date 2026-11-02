<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Exception\LimitExceeded;

/** One cumulative budget shared by every nested float context of a document. */
final class FloatWorkBudget
{
    private int $used = 0;

    public function __construct(private readonly int $maximum) {}

    public function account(): void
    {
        if (++$this->used > $this->maximum) {
            throw new LimitExceeded('Cumulative float placement work budget exhausted.');
        }
    }
}
