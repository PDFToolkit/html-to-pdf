<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Model;

final readonly class SemanticNode
{
    public function __construct(public string $id, public string $role, public ?string $parentId = null, public ?string $alt = null, public ?string $language = null, public ?string $scope = null, public ?bool $checked = null) {}
}
