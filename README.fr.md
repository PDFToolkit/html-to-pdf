# HTML vers PDF

[English](README.md) · [Français](README.fr.md)

Un moteur indépendant de pagination HTML/CSS en **PHP natif**, orienté objet et
fondé sur le cœur PDFToolkit. Il produit du texte Unicode sélectionnable, des
polices TrueType incorporées, des images et une structure logique PDF sans
navigateur ni moteur JavaScript.

La version 0.2 étend le profil d’entrée natif documenté, sans revendiquer l’intégralité
de HTML/CSS ou des fonctions iText pdfHTML. Par défaut, les fonctions absentes sont
refusées. Consulter la [couverture](docs/fr/coverage.md),
l’[architecture](docs/fr/architecture.md) et les [exemples](examples/README.fr.md).
Un PDF balisé ne constitue pas une certification PDF/UA ou PDF/A. Le travail suit
[CSS1 → CSS2 → CSS2.1 → modules](docs/fr/css-levels.md), avec des critères de qualification explicites.

L’[exemple CSS1](docs/fr/css1.md) montre des côtés de bordure indépendants et huit
styles visibles. Cette version valide aussi les déclarations originales avant
la cascade, traite `@import` dans le seul catalogue fourni et récupère certaines
erreurs syntaxiques en mode Report. Le registre compte 45 exigences partielles
et 17 absentes ; **CSS1 complet reste non qualifié**.

## Installation

```sh
composer require pdftoolkit/html-to-pdf:^0.2
```

PHP 8.3+ sur une architecture 64 bits, DOM et mbstring sont nécessaires.
`php-64bit` est une vérification de plateforme Composer, pas une extension à
installer séparément. Les dépendances de production sont des versions stables ;
le cœur PDFToolkit ne dépend pas de ce module optionnel.

## Conversion

```php
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;
use PDF\HtmlToPdf\HtmlSource;

$fonts = new FontSet(
    regular: new FontFace(file_get_contents('fonts/Lato-Regular.ttf')),
    bold: new FontFace(file_get_contents('fonts/Lato-Bold.ttf')),
);
$result = (new Converter())->convert(
    new HtmlSource('<!doctype html><html lang="fr"><title>Exemple</title>'
        .'<body><h1>Bonjour</h1><p>Unicode sélectionnable : café.</p></body></html>'),
    new ConversionOptions(fonts: $fonts),
);
file_put_contents('exemple.pdf', $result->pdf);
```

Les lectures et écritures de cet exemple appartiennent à l’application appelante.
Le convertisseur reçoit des octets ; il ne télécharge aucune URL, ne recherche
aucune police système et n’écrit aucun fichier implicitement. Fournir les vraies
variantes regular/bold/italic/bold-italic utilisées. Un glyphe, un script ou une
variante absent provoque une erreur explicite, sans gras artificiel ni police de
remplacement silencieuse.

Le [guide](docs/fr/usage.md) décrit les pages, catalogues explicites d’images/CSS,
diagnostics et limites. Après `composer install`, exécuter
`php -d memory_limit=512M examples/generate.php _build/examples` pour reproduire les exemples.

## Développement

```sh
composer install
composer check
php -d memory_limit=512M examples/generate.php _build/examples
python tools/validate_pdf.py --directory _build/examples
```

L’oracle indépendant demande `tools/validation-requirements.txt`, qpdf et Poppler.
La CI vérifie PHP 8.3, 8.4 et 8.5 et conserve PDF, rendus des pages et rapports.
Voir la [publication](RELEASING.fr.md).

## Origine et licence

Implémentation MIT ; les polices fournies conservent leurs licences OFL.
iText pdfHTML est un produit référencé par la PDF Association : une référence
fonctionnelle, sans constituer une norme ni une dépendance de code. Son code
AGPL/commercial n’a pas été copié ou traduit. Voir les
[références primaires](docs/fr/references.md).
