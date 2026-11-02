<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Tests\Css;

use PDF\HtmlToPdf\ConversionLimits;
use PDF\HtmlToPdf\Diagnostics;
use PDF\HtmlToPdf\Exception\ConversionException;
use PDF\HtmlToPdf\Exception\LimitExceeded;
use PDF\HtmlToPdf\Exception\UnsupportedFeature;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Parsing\HtmlCompiler;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\Tests\Parsing\CompilerTestCase;
use PDF\HtmlToPdf\UnsupportedPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImportTest extends CompilerTestCase
{
    public function testImportsResolveAgainstTheLinkedStylesheetAndCascadeBeforeItsRules(): void
    {
        $resources = new ResourceCatalog(stylesheets: ['styles/main.css' => '@import "../colors.css";p{font-size:20pt}', 'colors.css' => 'p{color:red}']);
        $document = $this->compile('<link rel="stylesheet" href="styles/main.css"><p id="x">X</p>', options: $this->options(resources: $resources));
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(20.0, $style->fontSize);
    }

    public function testExplicitDocumentBaseIsUsedForLinkedAndEmbeddedStylesheets(): void
    {
        $resources = new ResourceCatalog(stylesheets: ['https://example.test/css/main.css' => '@import "colors.css";p{font-size:20pt}', 'https://example.test/css/colors.css' => 'p{color:red}']);
        $options = $this->options(resources: $resources);
        $source = new HtmlSource('<link rel="stylesheet" href="../css/main.css"><p id="x">X</p>', baseUri: 'https://example.test/doc/index.html');
        $document = (new HtmlCompiler())->parse($source, $options, new Diagnostics());
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
        $source = new HtmlSource('<style>@import "../css/colors.css";</style><p id="x">X</p>', baseUri: 'https://example.test/doc/index.html');
        $document = (new HtmlCompiler())->parse($source, $options, new Diagnostics());
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testRepeatedImportsAreNotSuppressedByAGlobalVisitedSet(): void
    {
        $resources = new ResourceCatalog(stylesheets: ['a.css' => '@import "shared.css";p{color:blue}', 'b.css' => '@import "shared.css";', 'shared.css' => 'p{color:red}']);
        $document = $this->compile('<p id="x">X</p>', '@import "a.css";@import "b.css";', $this->options(resources: $resources));
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testOwningRulesAndImportantDeclarationsKeepTheirPrecedence(): void
    {
        $resources = new ResourceCatalog(stylesheets: ['a.css' => 'p{color:red!important;font-size:30pt}']);
        $document = $this->compile('<p id="x">X</p>', '@import url("a.css") print; p{color:blue;font-size:20pt}', $this->options(resources: $resources));
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(20.0, $style->fontSize);
    }

    public function testInactiveImportsAreNotRetrieved(): void
    {
        $document = $this->compile('<p id="x">X</p>', '@import "https://not-supplied.invalid/x.css" screen;p{color:red}');
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
    }

    public function testMissingImportsNeverReadTheFilesystem(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('No explicitly supplied CSS resource');
        $this->compile('<p>X</p>', '@import "file:///etc/passwd";');
    }

    public function testLateAndNestedImportsAreIgnoredInReportModeWithoutLookup(): void
    {
        $document = $this->compile('<p id="x">X</p>', 'p{color:red}@import "missing.css";@media print{@import "missing.css";p{font-size:20pt}}', $this->options(policy: UnsupportedPolicy::Report));
        $style = $this->find($document->root, 'x')->style;
        self::assertSame(1.0, $style->color->red);
        self::assertSame(20.0, $style->fontSize);
    }

    public function testCycleIsExplicitAndRecoveryKeepsTheFollowingRules(): void
    {
        $resources = new ResourceCatalog(stylesheets: ['a.css' => '@import "b.css";p{color:red}', 'b.css' => '@import "./a.css#cycle";p{color:blue}']);
        $diagnostics = new Diagnostics(UnsupportedPolicy::Report);
        $document = $this->compile('<p id="x">X</p>', '@import "a.css";', $this->options(resources: $resources, policy: UnsupportedPolicy::Report), $diagnostics);
        self::assertSame(1.0, $this->find($document->root, 'x')->style->color->red);
        self::assertSame(['css.import-cycle'], array_map(static fn($item) => $item->code, $diagnostics->all()));
    }

    public function testCycleIsRejectedByDefault(): void
    {
        $this->expectException(UnsupportedFeature::class);
        $resources = new ResourceCatalog(stylesheets: ['a.css' => '@import "a.css";']);
        $this->compile('<p>X</p>', '@import "a.css";', $this->options(resources: $resources));
    }

    /** @return iterable<string,array{ConversionLimits,string,array<string,string>}> */
    public static function importLimits(): iterable
    {
        yield 'count' => [new ConversionLimits(maxImports: 1), '@import "a.css";@import "a.css";', ['a.css' => 'p{color:red}']];
        yield 'depth' => [new ConversionLimits(maxImportDepth: 1), '@import "a.css";', ['a.css' => '@import "b.css";', 'b.css' => 'p{color:red}']];
        yield 'repeated bytes' => [new ConversionLimits(maxCssBytes: 45), '@import "a.css";@import "a.css";', ['a.css' => 'p{color:red}']];
        yield 'catalog bytes' => [new ConversionLimits(maxResourceBytes: 2), '@import "a.css";', ['a.css' => 'p{color:red}']];
    }

    #[DataProvider('importLimits')]
    public function testImportGraphIsCumulativelyBounded(ConversionLimits $limits, string $css, array $stylesheets): void
    {
        $this->expectException(LimitExceeded::class);
        $this->compile('<p>X</p>', $css, $this->options($limits, new ResourceCatalog(stylesheets: $stylesheets)));
    }
}
