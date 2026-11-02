<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;

/** Document-local integer numbering; an exhausted counter never becomes a float. */
final class ListCounter
{
    private ?int $next;

    public function __construct(?string $start = null)
    {
        $this->next = $start === null ? 1 : $this->integer($start);
    }

    public function next(?string $value = null): int
    {
        if ($value !== null) {
            $this->next = $this->integer($value);
        }
        $current = $this->next ?? throw new UnsupportedFeature('List counter exceeds the supported integer range.');
        $this->next = $current === PHP_INT_MAX ? null : $current + 1;

        return $current;
    }

    private function integer(string $value): int
    {
        $value = trim($value, " \t\r\n\f");
        if (preg_match('/^([+-]?)([0-9]+)$/D', $value, $parts) !== 1) {
            throw new UnsupportedFeature('List counter attributes require a signed decimal integer.');
        }
        $digits = ltrim($parts[2], '0');
        if ($digits === '') {
            return 0;
        }
        $negative = $parts[1] === '-';
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new UnsupportedFeature('List counter exceeds the supported integer range.');
        }

        return (int) (($negative ? '-' : '') . $digits);
    }
}
