# HTML vers PDF

[English](README.md) · [Français](README.fr.md)

Un moteur indépendant de pagination HTML/CSS en **PHP natif**, orienté objet et
fondé sur le cœur PDFToolkit. Il produit du texte Unicode sélectionnable, des
polices TrueType incorporées, des images et une structure logique PDF sans
navigateur ni moteur JavaScript.

La version 0.4 étend le profil d’entrée natif documenté. La couverture HTML/CSS
reste partielle et les fonctions absentes sont refusées par défaut. Consulter la [couverture](docs/fr/coverage.md),
l’[architecture](docs/fr/architecture.md) et les [exemples](examples/README.fr.md).
Un PDF balisé ne constitue pas une certification PDF/UA ou PDF/A. Le travail suit
[CSS1 → CSS2 → CSS2.1 → modules](docs/fr/css-levels.md), avec des critères de qualification explicites.

La version 0.4 ajoute `word-spacing`, `letter-spacing` et le retrait de première
ligne `text-indent`, avec longueurs signées héritées et retrait en pourcentage.
Les [exemples CSS1](docs/fr/css1.md) montrent ces propriétés de texte, les bordures
indépendantes et le cas historique boîtes/flottants/dégagement. Le texte reste
sélectionnable et les annotations de lien suivent sa géométrie ajustée. Le
registre compte 53 exigences partielles et 9 absentes ; **CSS1 complet reste
non qualifié**.

## Installation

```sh
composer require pdftoolkit/html-to-pdf:^0.4
```

Le core est installé sous son nom Composer canonique `pdftoolkit/pdf:^0.12.2`.
Si votre application exige directement `roukmoute/pdf`, remplacez cette exigence
par `pdftoolkit/pdf:^0.12.2` et actualisez le verrou avec Composer.
Les namespaces PHP et les API restent inchangés.

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

Cette implémentation indépendante est sous licence MIT et s’appuie sur les
spécifications primaires HTML/CSS. Les polices fournies conservent leurs licences
OFL ; les fichiers historiques W3C inchangés conservent leur notice BSD. Voir les
[références primaires et la provenance des ressources](docs/fr/references.md).
