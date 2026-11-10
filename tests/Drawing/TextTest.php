<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Drawing;

use PDF\HtmlToPdf\Drawing\Text;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Style\Color;
use PDF\HtmlToPdf\Style\TextSpacing;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    private static FontFace $font;

    public static function setUpBeforeClass(): void
    {
        self::$font = new FontFace(file_get_contents(__DIR__ . '/../Fixtures/fonts/NotoSans-Regular.ttf'));
    }

    public function testMovingKeepsEveryOtherConstructorField(): void
    {
        $text = $this->distinctText();
        $moved = $text->at(70.5, 80.25);
        self::assertNotSame($text, $moved);
        self::assertSame(array_replace(get_object_vars($text), ['x' => 70.5, 'y' => 80.25]), get_object_vars($moved));
        self::assertSame($this->constructorFields(), array_keys(get_object_vars($moved)));
    }

    public function testArtifactCopyDropsOnlyStructureAndLink(): void
    {
        $text = $this->distinctText();
        $artifact = $text->detached();
        self::assertSame(array_replace(get_object_vars($text), ['semanticId' => null, 'uri' => null]), get_object_vars($artifact));
        self::assertSame($text->spacing, $artifact->spacing);
        self::assertNotNull($text->semanticId);
        self::assertNotNull($text->uri);
    }

    /** Every promoted field has a value distinct from its default, so a forgotten field cannot go unnoticed. */
    private function distinctText(): Text
    {
        $text = new Text(self::$font, 'Witness', 11.5, 3.25, 4.75, new Color(0.1, 0.2, 0.3), 'semantic-7', 'https://example.test/', new TextSpacing(1.5, -0.5), true);
        $defaults = [];
        foreach ((new \ReflectionClass(Text::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }
        foreach ($defaults as $name => $default) {
            self::assertNotEquals($default, $text->{$name}, 'The witness must override the default of ' . $name);
        }

        return $text;
    }

    /** @return list<string> */
    private function constructorFields(): array
    {
        return array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionClass(Text::class))->getConstructor()?->getParameters() ?? []);
    }
}
