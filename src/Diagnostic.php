<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf;

final readonly class Diagnostic implements \JsonSerializable
{
    public function __construct(public string $code, public string $message, public ?string $nodeId = null) {}
    /** @return array{code:string,message:string,nodeId:?string} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code,'message' => $this->message,'nodeId' => $this->nodeId];
    }
}
