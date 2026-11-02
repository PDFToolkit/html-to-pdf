<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Layout;

use PDF\HtmlToPdf\Drawing\Command;
use PDF\HtmlToPdf\Drawing\Image;
use PDF\HtmlToPdf\Drawing\Rectangle;
use PDF\HtmlToPdf\Drawing\Text;

final readonly class CommandTranslation
{
    public function move(Command $command, float $x, float $y): Command
    {
        return match (true) {
            $command instanceof Text => new Text($command->font, $command->text, $command->size, $command->x + $x, $command->y + $y, $command->color, $command->semanticId, $command->uri),
            $command instanceof Image => new Image($command->x + $x, $command->y + $y, $command->width, $command->height, $command->asset, $command->semanticId),
            $command instanceof Rectangle => new Rectangle($command->x + $x, $command->y + $y, $command->width, $command->height, $command->color, $command->borderColor, $command->borderWidth, $command->semanticId),
            default => throw new \LogicException('Unsupported drawing command.'),
        };
    }
}
