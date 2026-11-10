<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;

final class Diagnostics
{
    /** @var list<Diagnostic> */ private array $items = [];
    private int $required = 0;
    private int $notes = 0;
    public function __construct(private readonly UnsupportedPolicy $policy = UnsupportedPolicy::Reject, private readonly int $maximum = 100)
    {
        if ($maximum < 1) {
            throw new \InvalidArgumentException('A positive diagnostic budget is required.');
        }
    }
    public function add(string $code, string $message, ?string $nodeId = null): void
    {
        if ($this->required >= $this->maximum) {
            throw new LimitExceeded('Diagnostic budget exhausted.');
        }
        ++$this->required;
        $this->items[] = new Diagnostic($code, $message, $nodeId);
    }
    /**
     * Informational note about a behavior the specification defines, recorded in both
     * policies. Notes have their own allowance of the same size: they never stop a
     * conversion and never take the place of a diagnostic that add() must record.
     */
    public function inform(string $code, string $message, ?string $nodeId = null): bool
    {
        if ($this->notes >= $this->maximum) {
            return false;
        }
        ++$this->notes;
        $this->items[] = new Diagnostic($code, $message, $nodeId);

        return true;
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
