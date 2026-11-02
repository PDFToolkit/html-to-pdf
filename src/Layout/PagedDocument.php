<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Model\SemanticNode;

final readonly class PagedDocument
{
    /**
     * @param list<LayoutPage> $pages
     * @param list<SemanticNode> $semantics
     */
    public function __construct(public array $pages, public array $semantics, public string $title = '', public string $language = 'en') {}
}
