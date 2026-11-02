# Utilisation et politiques

[English](../usage.md) · [Français](usage.md)

## Entrées explicites

La bibliothèque reçoit du HTML UTF-8 et éventuellement une chaîne CSS.
`HtmlSource::stylesheet` est une feuille de style auteur. Attributs `style`,
éléments `style` et feuilles liées participent à la cascade documentée. Une
feuille externe est recherchée dans `ResourceCatalog`, jamais téléchargée.

```php
use PDF\HtmlToPdf\ConversionOptions;
use PDF\HtmlToPdf\Converter;
use PDF\HtmlToPdf\HtmlSource;
use PDF\HtmlToPdf\Layout\PageSettings;
use PDF\HtmlToPdf\Resource\ImageAsset;
use PDF\HtmlToPdf\Resource\ResourceCatalog;
use PDF\HtmlToPdf\Style\Edges;

$resources = new ResourceCatalog(
    images: ['cover.png' => new ImageAsset($pngBytes)],
    stylesheets: ['print.css' => 'p { color: #176a80; }'],
);
$options = new ConversionOptions(
    fonts: $fonts,
    page: new PageSettings(width: 595.275590551, height: 841.88976378,
        margin: new Edges(36, 36, 36, 36)),
    resources: $resources,
);
$result = (new Converter())->convert(new HtmlSource($html), $options);
```

`$fonts` est le `FontSet` explicite du README. `$pngBytes` et `$html` sont fournis
par l’appelant. Les clés sont opaques, exactes et sensibles à la casse. Une clé
ressemblant à une URL ne fonctionne que si elle est présente dans le catalogue.
Aucune résolution HTTP, fichier, répertoire ou `data:` n’est effectuée. Les usages
répétés d’une image partagent son incorporation. `alt=""` marque explicitement
une image décorative ; une figure informative demande une description utile.

Seuls les liens textuels absolus ASCII `http`, `https` et `mailto` sont traités.
Ils deviennent des annotations URI avec références de structure, sans être suivis
pendant la conversion. Destinations internes, actions JavaScript et soumission
de formulaires sont hors profil.

## Pages, polices et CSS

Les dimensions de page sont en points. Par défaut : A4 avec marges de 36 points.
La conversion CSS utilise `1px = 0,75pt` ; millimètres, centimètres, pouces, points
et valeurs relatives à la police prises en charge sont calculés avant placement.
La page se configure par `PageSettings`, pas par `@page` dans cette version.

Fournir des TrueType statiques à contours `glyf` dont l’incorporation est permise.
Le parseur vérifie les restrictions d’incorporation et les `FontLimits` explicites.
Les vraies variantes sont nécessaires. Le profil couvre le texte horizontal
latin, grec et cyrillique, ainsi que les caractères Common/Inherited admissibles.
Il ne réalise pas bidi Unicode, découpage par script, shaping arabe/indien,
mise en page CJK ou écriture verticale. Un glyphe ou script absent échoue
explicitement, même en mode Report.

Les tableaux demandent `table-layout: fixed` en mode strict. Définir les largeurs
dans la première ligne ou utiliser des colonnes égales. La [couverture](coverage.md)
précise les restrictions de fragmentation, espaces et boîtes.

## Fonctions absentes

`UnsupportedPolicy::Reject` est la valeur par défaut. `UnsupportedFeature` arrête
la conversion lorsqu’une fonction reconnue absente est rencontrée. Pour examiner
volontairement une approximation bornée, passer
`unsupported: UnsupportedPolicy::Report` et lire chaque entrée de
`ConversionResult::diagnostics` (`code`, `message`, `nodeId`). Ce mode ne garantit
pas la fidélité et ne masque ni ressource absente, lien dangereux, glyphe absent,
débordement indivisible, ni limite dépassée. Une erreur ne produit pas de résultat
partiel présenté comme réussi. Aucun JavaScript n’est exécuté.

## Limites

Les budgets appartiennent à chaque conversion. Le document précédent ne peut
ni épuiser ni réinitialiser les compteurs du suivant. Valeurs configurables :

| `ConversionLimits` | Défaut |
|---|---:|
| Octets HTML | 1 Mio |
| Octets CSS analysés cumulés | 256 Kio |
| Nœuds HTML/sémantiques | 10 000 |
| Profondeur | 64 |
| Caractères de texte | 100 000 |
| Budget de balayage règles/déclarations CSS | 1 000 |
| Travail sélecteurs (octets du sélecteur × éléments candidats) | 500 000 |
| Pages | 200 |
| Commandes de dessin | 50 000 |
| Octets du catalogue, ressources inutilisées comprises | 16 Mio |
| Pixels cumulés des images du catalogue | 16 000 000 |
| Octets PDF sérialisés | 32 Mio |

`ImageAsset` applique aussi ses propres limites d’octets et pixels avant
incorporation. `FontLimits` borne l’analyse de police et chaque opération de
shaping. La taille de sortie est vérifiée après sérialisation : ce n’est pas une
limite mémoire du processus. Pour des entrées non fiables, conserver aussi les
limites mémoire/temps habituelles des workers applicatifs. Augmenter un budget
n’ajoute aucune fonction manquante.

Les exemples Lato complets utilisent trois variantes préparées et s’exécutent avec
`php -d memory_limit=512M examples/generate.php _build/examples`. Les tables
OpenType analysées occupent davantage de mémoire que les octets de police :
environ 52 Mio par variante Lato mesurée. Les variantes inutilisées restent
non préparées.
