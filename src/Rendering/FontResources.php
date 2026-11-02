<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Rendering;

use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontRun;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class FontResources
{
    /** @var \SplObjectStorage<FontFace, EmbeddedFont> */
    private \SplObjectStorage $fonts;
    /** @var \SplObjectStorage<Text, FontRun> */
    private \SplObjectStorage $runs;

    public function __construct(private readonly RenderBudget $budget)
    {
        $this->fonts = new \SplObjectStorage();
        $this->runs = new \SplObjectStorage();
    }

    public function prepare(Text $command): void
    {
        if ($this->runs->offsetExists($command)) {
            $this->budget->glyphs(count($this->runs[$command]->text->glyphs));

            return;
        }
        if (!$this->fonts->offsetExists($command->font)) {
            $this->budget->resource(strlen($command->font->program->source->bytes));
            $this->fonts[$command->font] = new EmbeddedFont($command->font, new NameType('F' . (count($this->fonts) + 1)));
        }
        $run = $command->font->shape($command->text, $command->size);
        $this->budget->glyphs(count($run->text->glyphs));
        $this->fonts[$command->font]->add($run);
        $this->runs[$command] = $run;
    }

    public function run(Text $command): FontRun
    {
        return $this->runs[$command];
    }

    public function font(Text $command): EmbeddedFont
    {
        return $this->fonts[$command->font];
    }

    public function dictionary(): DictionaryType
    {
        $entries = [];
        foreach ($this->fonts as $face) {
            $font = $this->fonts[$face];
            $entries[] = new Entry($font->name, new IndirectReference($font->embedding()->font));
        }

        return new DictionaryType(...$entries);
    }
}
