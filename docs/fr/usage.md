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
par l’appelant. Les clés d’images restent exactes et sensibles à la casse. Les
références CSS sont résolues en clés de catalogue selon les règles ci-dessous.
Une clé ressemblant à une URL ne fonctionne que si elle est fournie : cette
résolution ne charge aucune ressource HTTP, fichier ou `data:`. Les usages répétés
d’une image partagent son incorporation. `alt=""` marque explicitement une image
décorative ; une figure informative demande une description utile.

Les liens textuels ASCII `http`, `https` et `mailto` deviennent des annotations
URI avec références de structure, sans être suivis pendant la conversion. Une
cible relative demande un `HtmlSource::baseUri` explicite ; son fragment reste
dans l’URI externe. Sans base prise en charge, la cible relative est refusée. Destinations internes, actions JavaScript et soumission
de formulaires sont hors profil.

## Imports et identité des feuilles CSS

`@import "colors.css";` et `@import url("colors.css") print;` ne lisent que les
octets déjà enregistrés dans `ResourceCatalog::stylesheets`. Les imports doivent
précéder les autres règles au niveau principal de la feuille. Leurs règles
précèdent celles de la feuille appelante ; spécificité et `!important` conservent
leur priorité habituelle. Un import répété est retraité à sa nouvelle position.

```php
$resources = new ResourceCatalog(stylesheets: [
    'https://example.invalid/styles/print.css' =>
        '@import "colors.css"; p { font-size: 12pt; }',
    'https://example.invalid/styles/colors.css' => 'p { color: #176a80; }',
]);
$source = new HtmlSource(
    '<link rel="stylesheet" href="../styles/print.css"><p>CSS du catalogue.</p>',
    baseUri: 'https://example.invalid/documents/rapport.html',
);
$result = (new Converter())->convert($source,
    new ConversionOptions(fonts: $fonts, resources: $resources));
```

`HtmlSource::baseUri` est facultatif et concerne les références CSS et les cibles
de liens textuels, pas les clés d’images. Une feuille liée devient la base de ses
propres imports ; `style` intégré et `HtmlSource::stylesheet` utilisent la base du
document fournie. Sans base, les noms relatifs désignent la racine du catalogue.
Les segments pointés sont normalisés, les fragments exclus de l’identité CSS et
les caractères de chemin encodés en pourcentage restent encodés. La clé obtenue
doit exister exactement dans le catalogue. Son schéma d’URL apparent n’autorise
aucun accès extérieur.

Seul le profil média print/all pris en charge active un import. Un import screen
n’est pas recherché. Un cycle actif est refusé par défaut ; Report le signale et
poursuit les règles suivantes. Les imports mal placés sont également refusés ou
signalés puis ignorés. Une ressource absente échoue dans les deux modes. Nombre,
profondeur et octets CSS cumulés restent bornés, imports répétés compris ; les
imports inactifs consomment aussi le budget du nombre d’imports.

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

## Familles de polices explicites

Les listes CSS `font-family` choisissent la première famille enregistrée dans un
`FontCatalog`. Les noms sont insensibles à la casse ; un nom entre guillemets
reste distinct d’un mot-clé générique. Associer les génériques volontairement :

```php
use PDF\HtmlToPdf\Font\FontCatalog;
use PDF\HtmlToPdf\Font\FontFace;
use PDF\HtmlToPdf\Font\FontSet;

$regular = new FontFace($regularBytes);
$sans = new FontSet($regular, bold: new FontFace($boldBytes));
$fonts = new FontSet($regular, catalog: new FontCatalog(
    families: ['Report Sans' => $sans],
    genericFamilies: ['sans-serif' => $sans],
));
// CSS : font: bold 10px/1.3 "Report Sans", sans-serif;
```

Le `FontSet` par défaut sert toujours les documents sans déclaration CSS de
famille. Une liste explicite sans correspondance enregistrée échoue. Après la
sélection, la variante et chaque glyphe doivent exister dans la famille fournie :
aucun remplacement par caractère, variante synthétique ou accès au système.
Le raccourci `font` pris en charge est développé intégralement et réinitialise
les composants omis. Petites capitales, tailles par mots-clés, polices système
et correspondance relative des graisses restent absentes.

## Espacements et retrait de première ligne

```css
p { word-spacing: .2em; letter-spacing: 1px; text-indent: 10%; }
p.compact { word-spacing: -1px; letter-spacing: -.02em; text-indent: -12px; }
```

`word-spacing` et `letter-spacing` acceptent `normal`, zéro et les longueurs
signées `px`, `pt`, `pc`, `mm`, `cm`, `in` ou `em` ; l’extension `rem` existante
fonctionne aussi. Les espacements en pourcentage sont refusés. `text-indent`
admet les mêmes longueurs ainsi que les pourcentages signés, avec zéro comme
valeur initiale. `ex` reste absent. Les valeurs invalides sont refusées avant
cascade : en mode Report, une déclaration invalide ultérieure ne supprime pas
une valeur valide précédente.

Ces trois propriétés sont héritées. Les longueurs relatives sont calculées avec
la taille de police de l’élément puis héritées en points absolus ; augmenter la
police d’un enfant ne recalcule pas sa valeur `em` héritée. Un retrait en
pourcentage reste différé jusqu’au placement et utilise la largeur de contenu
du bloc contenant les lignes. Il ne dépend pas du rétrécissement temporaire d’une
ligne par un flottant. Seule la première ligne formatée admissible est décalée :
`br`, nouvelle page et fragments inline anonymes suivant un bloc dans le flux ne
recommencent pas le retrait du parent. Chaque bloc descendant applique encore
son propre retrait de première ligne, hérité ou spécifié.

L’espacement des mots s’applique aux espaces U+0020 et insécables U+00A0 restants
après traitement des blancs. L’espace insécable conserve son rôle. Un espacement
de lettres non nul ajoute une avance après chaque graphème, y compris à la fin,
selon les témoins ciblés Chromium/Firefox. Les accents combinants restent attachés
et les ligatures facultatives sont désactivées. `normal` et zéro conservent le
shaping initial. Ce profil horizontal latin/grec/cyrillique borné n’annonce pas
CSS Text complet. Une avance totale négative est refusée. Un espacement de
lettres non nul sur un graphème partagé entre plusieurs spans inline est également refusé, car le
shaping à travers ces frontières de fragments reste absent. Un retrait ne
laissant aucune largeur de ligne positive échoue explicitement. Les tabulations
préservées avec `letter-spacing` ou `word-spacing` non nul lèvent
`UnsupportedFeature` : le profil de tabulation existant à espacement normal est
conservé, sans annoncer un placement exact des taquets CSS. Les budgets
existants de shaping, commandes et sortie restent applicables.

Les mêmes avances déterminent les retours, le texte PDF, les fonds inline,
soulignements et annotations de lien. `ComputedStyle::textSpacing` expose les
valeurs immuables `TextSpacing` en points (`null` signifie `normal`) ;
`ComputedStyle::textIndent` expose un `TextIndent` signé avec son indicateur de
pourcentage. L’[exemple et ses contrôles indépendants](css1.md#espacements-et-retrait-de-première-ligne)
montrent le comportement implémenté. Justification, transformation, sélection
complète des polices et décorations supplémentaires restent hors de ce jalon.

## Flottants bornés et contrôles statiques

`float:left/right` exige une largeur explicite, y compris les pourcentages pris
en charge. Les flottants imbriqués ont leur propre contexte ; le texte utilise
l’espace libre et `clear:left/right/both` descend sous les flottants concernés.
**Tout le document contenant des flottants visibles doit tenir sur une page.**
La fragmentation, la largeur intrinsèque des flottants et leur combinaison avec
des tableaux sont absentes. La fusion parent-enfant des marges dans ces contextes
est refusée ; une bordure ou un padding explicite peut les séparer. Le débordement
d’une hauteur fixe reste visible, mais toute l’étendue peinte doit tenir sur la page.
Un bloc de flux ordinaire avec fond ou bordure ne peut chevaucher un flottant
antérieur. Un flottant introduit après du texte sur la même ligne est également
refusé ; ces cas de superposition et de recomposition des lignes restent absents.

Le fond de `html` peint toute la page. S’il est transparent, le fond du body est
propagé au fond de page sans être peint deux fois. Les marges et bordures du body
conservent leur géométrie. Les nœuds source `dl/dt/dd` restent distincts et sont
projetés en `Sect/P/Sect` PDF, sans modèle plus riche de liste de définitions.
Les enveloppes inline simples peuvent être séparées autour de blocs ; celles
qui portent des décorations ou une portée de lien/langue autour des blocs sont refusées.

Formulaires et contrôles sont refusés par défaut. Un instantané statique explicite
admet les boutons radio et conserve l’état coché/désactivé dans un dessin vectoriel :

```php
use PDF\HtmlToPdf\FormControls;

$options = new ConversionOptions(
    fonts: $fonts,
    formControls: FormControls::StaticSnapshot,
);
```

La sortie porte des attributs logiques Form/PrintField, sans champ PDF interactif,
action d’envoi ou JavaScript. Les autres contrôles, dimensions/décorations radio
personnalisées et radios bloc/flottantes sont absents. Report les omet avec un
diagnostic ; le mode strict les refuse. L’[exemple CSS1 historique](css1.md)
fournit la configuration explicite complète.

## Validation des déclarations, récupération et bordures

Le texte original d’une valeur est validé avant de remplacer une valeur de la
cascade. En Report, `color: red; color: invalid` conserve le rouge ; un raccourci
malformé ne peut appliquer seulement certains côtés. Le bornage RGB utilise les
composantes numériques originales, sans passer par une couleur réécrite par le
parseur. Les identifiants échappés fonctionnent dans les sélecteurs ; les valeurs
de déclaration échappées comme `color: r\65 d` restent hors profil, avec diagnostic
ou exception.

Reject reste la politique par défaut pour le CSS malformé ou non pris en charge.
Report signale certaines erreurs syntaxiques et réanalyse la source bornée pour
conserver les déclarations voisines valides et les règles suivantes. Ce n’est
pas toute la récupération syntaxique d’un navigateur. Une liste contenant un
sélecteur invalide ou absent est ignorée entièrement : `p, a:hover` ne colore
pas `p` en Report. États dynamiques, pseudo-éléments et feuilles d’origine
utilisateur restent absents.

Les octets CSS doivent déjà être en UTF-8. Une directive initiale
`@charset "UTF-8";` est acceptée ; les encodages inconnus ou différents, directives
répétées ou mal placées sont refusés avant l’analyse. Report enregistre
`css.charset` et ignore cette feuille entière, en conservant les autres feuilles
fournies. Aucune conversion d’encodage n’est effectuée.

Les bordures possèdent largeur, style et couleur indépendants en haut, à droite,
en bas et à gauche : `solid`, `dotted`, `dashed`, `double`, `groove`, `ridge`,
`inset`, `outset` et `none`. Les raccourcis sont développés avant cascade ; leurs
composantes omises reprennent les valeurs initiales, et `inherit` reprend les
côtés calculés du parent. `none` utilise une largeur nulle ; une bordure de style
visible et de couleur transparente conserve sa largeur géométrique.

Le module choisit `thin = 1px`, `medium = 3px`, `thick = 5px`, soit
0,75/2,25/3,75 points PDF. Ces dimensions relèvent de l’implémentation, pas d’une
valeur universelle imposée par CSS. Les bordures des boîtes inline et les bordures
fusionnées de tableau restent absentes. L’[exemple CSS1](css1.md) illustre le
profil de blocs/cellules pris en charge.

Pour inspecter les styles calculés, utiliser `ComputedStyle::borderEdges()` et
les objets immuables `Borders`/`BorderSide`. Les anciens champs `borderWidth` et
`borderColor` restent des projections du côté haut dans les styles compilés ;
si ce côté est transparent, la projection de couleur utilise celle du texte.
Ils ne représentent ni les quatre côtés ni la transparence. Un style 0.1
construit directement sans `borders` décrit encore une bordure pleine uniforme.

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
| Profondeur HTML/CSS et des arbres de sélecteurs | 64 |
| Caractères de texte | 100 000 |
| Budget de balayage règles/déclarations CSS | 1 000 |
| Travail sélecteurs (octets du sélecteur × éléments candidats) | 500 000 |
| Imports CSS (`maxImports`) | 128 |
| Profondeur des imports CSS (`maxImportDepth`) | 16 |
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
