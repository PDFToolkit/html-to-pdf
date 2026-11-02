<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

/** Positive CSS adjoining margins form one maximum, including through empty blocks. */
final readonly class MarginCollapser
{
    /** @param list<FlowItem> $items */
    public function firstInFlow(array $items): ?FlowItem
    {
        foreach ($items as $item) {
            // An ancestor's padding or border can attach in-flow height to a float slice.
            if (!$item->outOfFlow || $item->height > 0) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<FlowItem> $items
     * @return list<FlowItem>
     */
    public function collapse(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item->extent() === 0.0 && $item->commands === [] && !$item->breakBefore && !$item->breakAfter && !$item->marginBoundary) {
                continue;
            }
            $last = $result === [] ? null : $result[array_key_last($result)];
            if ($item->margin && $last?->margin && !$last->breakAfter && !$item->breakBefore) {
                $last->height = max($last->height, $item->height);
                $last->avoidGroups = array_values(array_unique([...$last->avoidGroups, ...$item->avoidGroups]));
                $last->breakAfter = $item->breakAfter;
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }
}
