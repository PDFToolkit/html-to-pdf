<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Css;

use Symfony\Component\CssSelector\Node\Specificity;

final readonly class CascadedValue
{
    public function __construct(public string $value, public bool $important, public bool $inline, public Specificity $specificity, public int $order) {}

    public function overrides(self $other): bool
    {
        if ($this->important !== $other->important) {
            return $this->important;
        }
        if ($this->inline !== $other->inline) {
            return $this->inline;
        }
        $comparison = $this->specificity->compareTo($other->specificity);
        return $comparison > 0 || ($comparison === 0 && $this->order >= $other->order);
    }
}
