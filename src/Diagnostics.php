<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;

final class Diagnostics
{
    /** @var list<Diagnostic> */ private array $items = [];
    public function __construct(private readonly UnsupportedPolicy $policy = UnsupportedPolicy::Reject, private readonly int $maximum = 100)
    {
        if ($maximum < 1) {
            throw new \InvalidArgumentException('A positive diagnostic budget is required.');
        }
    }
    public function add(string $code, string $message, ?string $nodeId = null): void
    {
        if (count($this->items) >= $this->maximum) {
            throw new LimitExceeded('Diagnostic budget exhausted.');
        }
        $this->items[] = new Diagnostic($code, $message, $nodeId);
    }
    public function unsupported(string $code, string $message, ?string $nodeId = null): void
    {
        if ($this->policy === UnsupportedPolicy::Reject) {
            throw new UnsupportedFeature($message);
        }
        $this->add($code, $message, $nodeId);
    }
    /** @return list<Diagnostic> */ public function all(): array
    {
        return $this->items;
    }
}
