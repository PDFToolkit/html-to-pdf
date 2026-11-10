<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Drawing\Command;
use PDF\HtmlToPdf\Drawing\BorderBox;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;

/** Pagination operates on indivisible lines/rows and always consumes a slice or fails. */
final class Paginator
{
    /** @var list<LayoutPage> */
    private array $pages = [];
    /** @var list<Command> */
    private array $commands = [];
    private float $used = 0;
    private int $commandCount = 0;
    private readonly CommandTranslation $translation;

    public function __construct(private readonly ConversionOptions $options, private readonly Diagnostics $diagnostics)
    {
        $this->translation = new CommandTranslation();
    }

    /** @param list<FlowItem> $flow
     * @return list<LayoutPage>
     */
    public function paginate(array $flow): array
    {
        $height = $this->options->page->contentHeight();
        $groups = [];
        $paragraphs = [];
        foreach ($flow as $index => $item) {
            if ($item->height > $height + 0.001) {
                throw new UnsupportedFeature('An indivisible layout slice is taller than the available page.');
            }
            foreach ($item->avoidGroups as $group) {
                $groups[$group] ??= ['first' => $index, 'height' => 0.0];
                $groups[$group]['height'] += $item->height;
            }
            if ($item->paragraph !== null) {
                $paragraphs[$item->paragraph] ??= ['first' => $index, 'last' => $index];
                $paragraphs[$item->paragraph]['last'] = $index;
            }
        }
        $cut = null;
        $after = false;
        foreach ($flow as $index => $item) {
            $newPage = false;
            if ($after || $item->breakBefore || $cut === $index) {
                $newPage = $this->advance();
                $cut = null;
            }
            foreach ($item->avoidGroups as $group) {
                $definition = $groups[$group];
                if ($definition['first'] !== $index) {
                    continue;
                }
                if ($definition['height'] > $height + 0.001) {
                    $this->diagnostics->add('layout.avoid-too-tall', 'A break-inside:avoid box exceeds a page and must fragment.', $group);
                } elseif ($definition['height'] > $height - $this->used + 0.001) {
                    $newPage = $this->advance() || $newPage;
                }
            }
            if ($item->height > $height - $this->used + 0.001) {
                $newPage = $this->advance() || $newPage;
            }
            if ($newPage && $item->headers !== []) {
                foreach ($item->headers as $header) {
                    $this->paint($header, true);
                }
            }
            if ($item->paragraph !== null && ($paragraphs[$item->paragraph]['first'] === $index || $newPage)) {
                $end = $paragraphs[$item->paragraph]['last'];
                $fit = 0;
                $lineHeight = 0.0;
                for ($position = $index; $position <= $end; ++$position) {
                    if ($lineHeight + $flow[$position]->height > $height - $this->used + 0.001) {
                        break;
                    }
                    $lineHeight += $flow[$position]->height;
                    ++$fit;
                }
                $remaining = $end - $index + 1;
                if ($fit < $remaining) {
                    $allowed = min($fit, $remaining - $item->widows);
                    if ($allowed < $item->orphans) {
                        if ($this->used > 0) {
                            $this->advance();
                            $fit = 0;
                            $lineHeight = 0.0;
                            for ($position = $index; $position <= $end; ++$position) {
                                if ($lineHeight + $flow[$position]->height > $height + 0.001) {
                                    break;
                                }
                                $lineHeight += $flow[$position]->height;
                                ++$fit;
                            }
                            $allowed = $fit >= $remaining ? $remaining : min($fit, $remaining - $item->widows);
                        }
                        if ($allowed < min($remaining, $item->orphans)) {
                            throw new UnsupportedFeature('The requested widows/orphans cannot fit within the page geometry.');
                        }
                    }
                    if ($allowed < $remaining) {
                        $cut = $index + $allowed;
                    }
                }
            }
            if ($item->height > $height - $this->used + 0.001) {
                throw new UnsupportedFeature('The repeated table header and current slice cannot fit on a page.');
            }
            $this->paint($item);
            $after = $item->breakAfter;
        }
        $this->finish();

        return $this->pages;
    }

    private function paint(FlowItem $item, bool $artifact = false): void
    {
        $this->commandCount += count($item->commands);
        if ($this->commandCount > $this->options->limits->maxCommands) {
            throw new LimitExceeded('PDF drawing command budget exhausted.');
        }
        foreach ($item->commands as $command) {
            if ($artifact) {
                $command = match (true) {
                    $command instanceof BorderBox => new BorderBox($command->x, $command->y, $command->width, $command->height, $command->borders, $command->offset, $command->totalHeight),
                    $command instanceof Text => $command->detached(),
                    $command instanceof Image => new Image($command->x, $command->y, $command->width, $command->height, $command->asset),
                    $command instanceof Rectangle => new Rectangle($command->x, $command->y, $command->width, $command->height, $command->color, $command->borderColor, $command->borderWidth),
                    default => throw new \LogicException('Unsupported repeated table drawing command.'),
                };
            }
            $this->commands[] = $this->translation->move($command, $this->options->page->margin->left, $this->options->page->margin->top + $this->used);
        }
        $this->used += $item->height;
    }

    private function advance(): bool
    {
        if ($this->used === 0.0 && $this->commands === []) {
            return false;
        }
        $this->finish();
        $this->commands = [];
        $this->used = 0;

        return true;
    }

    private function finish(): void
    {
        if (count($this->pages) >= $this->options->limits->maxPages) {
            throw new LimitExceeded('PDF page budget exhausted.');
        }
        $this->pages[] = new LayoutPage($this->options->page->width, $this->options->page->height, $this->commands);
    }
}
