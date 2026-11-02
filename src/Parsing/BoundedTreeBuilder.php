<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Parsing;

use Masterminds\HTML5\Parser\DOMTreeBuilder;
use PDF\HtmlToPdf\Diagnostics;

/** Limits are enforced as tokens arrive, before allocating the corresponding DOM nodes. */
final class BoundedTreeBuilder extends DOMTreeBuilder
{
    public function __construct(private readonly ParseBudget $budget, private readonly Diagnostics $diagnostics)
    {
        parent::__construct(false, ['disable_html_ns' => true, 'encoding' => 'UTF-8']);
    }

    /**
     * @param string $name
     * @param array<string,string|null> $attributes
     * @param bool $selfClosing
     * @return int
     */
    public function startTag($name, $attributes = [], $selfClosing = false)
    {
        $this->budget->node($this->depth() + 1);
        foreach ($attributes as $_attribute) {
            $this->budget->node($this->depth() + 2);
        }
        return parent::startTag($name, $attributes, $selfClosing);
    }

    /** @param string $data */
    public function text($data): void
    {
        if ($data !== '') {
            $this->budget->node($this->depth() + 1);
            $this->budget->text($data);
        }
        parent::text($data);
    }

    /** @param string $cdata */
    public function comment($cdata): void
    {
        $this->budget->node($this->depth() + 1);
        // Comments have no visual or semantic output; do not retain their bytes twice.
    }

    /** @param string $data */
    public function cdata($data): void
    {
        $this->text($data);
    }

    /**
     * @param string $name
     * @param string|null $data
     */
    public function processingInstruction($name, $data = null): void
    {
        $this->budget->node($this->depth() + 1);
        $this->diagnostics->unsupported('html.processing-instruction', 'Processing instructions are not supported.');
    }

    /**
     * @param string $msg
     * @param int $line
     * @param int $col
     */
    public function parseError($msg, $line = 0, $col = 0): void
    {
        if ($msg !== 'No DOCTYPE specified.') {
            $this->diagnostics->add('html.parse', $msg . ' (line ' . $line . ', column ' . $col . ')');
        }
    }

    private function depth(): int
    {
        $depth = 0;
        $node = $this->current;
        while ($node instanceof \DOMElement) {
            ++$depth;
            $node = $node->parentNode;
        }
        return $depth;
    }
}
