<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Exception\UnsupportedFeature;

/** Preserve paint overflow while combining a bounded formatting context. */
final readonly class FlowAssembly
{
    /** @param list<FlowItem> $flow */
    public function combine(array $flow, ?float $height = null): FlowItem
    {
        $commands = [];
        $offset = 0.0;
        $extent = 0.0;
        $translation = new CommandTranslation();
        foreach ($flow as $index => $item) {
            if (($item->breakBefore && $index !== 0) || ($item->breakAfter && $index !== array_key_last($flow))) {
                throw new UnsupportedFeature('Explicit page breaks inside an indivisible float context are unsupported.');
            }
            foreach ($item->commands as $command) {
                $commands[] = $translation->move($command, 0, $offset);
            }
            $extent = max($extent, $offset + $item->extent());
            $offset += $item->height;
        }
        $result = new FlowItem($height ?? $offset, $commands);
        $result->paintHeight = max($result->height, $extent);
        if ($flow !== []) {
            $result->breakBefore = $flow[0]->breakBefore;
            $result->breakAfter = $flow[array_key_last($flow)]->breakAfter;
        }

        return $result;
    }
}
