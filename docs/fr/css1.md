# CSS1 : polices, espacements, flottants et bordures

[English](../css1.md) · [Français](css1.md)

## Propriétés de police

La version 0.5 parcourt les six propriétés de police de CSS1 et l’appariement de
la section 5.2.1 : mots-clés de taille absolus et relatifs, les neuf graisses avec
`bolder` et `lighter`, `italic` et `oblique`, `small-caps`, le raccourci `font`,
l’appariement sur la liste de familles et le repli caractère par caractère. Le
[guide](usage.md#familles-de-polices-explicites) décrit les faces étiquetées, les
étapes de l’appariement, les diagnostics informatifs et les limites.

[Source HTML](../../examples/sources/fonts.html) ·
[Liaisons de polices](../../examples/sources/fonts.json) ·
[Générateur](../../examples/generate-fonts.php)

![Première page du PDF natif des propriétés de police](../../examples/previews/fonts.png)

```css
body { margin: 0; font: 14px/20px Lato, "Noto Sans"; color: #1d3340; }
.larger { font-size: larger; }
.bolder { font-weight: bolder; }
.oblique { font-style: oblique; }
.caps { font-variant: small-caps; }
.inherited { font: italic small-caps bold 25px/50px Ladder; }
```

L’exemple fournit cinq programmes TrueType, quatre faces de Lato et Noto Sans
Regular, et les lie à des familles étiquetées par l’appelant. Les familles
Ladder, Scale, Slant et Engraved donnent volontairement à un programme une
étiquette inhabituelle : chaque réponse possible d’une règle est ainsi dessinée
par un programme différent, et la face choisie se lit dans les seules avances des
glyphes. Ses 31 lignes mesurées portent 130 échantillons courts, sans crénage ni
ligature. Le manifeste conserve les empreintes de la source, des liaisons et de
chaque fichier de police.

```sh
php -d memory_limit=512M examples/generate-fonts.php _build/fonts
node tools/validate_fonts.cjs _build/fonts/browser
python tools/validate_fonts.py --pdf _build/fonts/fonts.pdf \
  --manifest _build/fonts/manifest.json \
  --browser-report _build/fonts/browser/browser-report.json \
  --output _build/fonts/pdf
python tools/test_fonts_oracle.py
node tools/test_fonts_oracle.cjs
```

Installer les dépendances Python verrouillées dans `tools/validation-requirements.txt`,
qpdf, Poppler et les navigateurs indiqués plus bas. L’oracle PDF trouve `qpdf`,
`pdftotext` et `pdftoppm` dans le `PATH`, ou par ses options `--qpdf`,
`--pdftotext` et `--pdftoppm`.

Les valeurs attendues sont dérivées deux fois, en JavaScript et en Python, depuis
CSS1 5.2, CSS 2.1 15 et les tables des polices ; aucun des deux oracles ne lit les
sources du convertisseur. L’oracle navigateur compare valeurs calculées,
positions, largeurs et face retenue par chaque navigateur. L’oracle PDF identifie
chaque programme incorporé par rapport aux fichiers fournis, puis contrôle face,
corps, identifiants de glyphes, avances, position et texte Unicode de chaque
fragment, les diagnostics informatifs et la présence d’encre à l’emplacement de
chaque échantillon. Les valeurs internes au PDF sont comparées à 0,000001 pt
près ; les largeurs et positions face aux navigateurs à 0,09 pt et 0,225 pt près.
Les deux oracles refusent de s’exécuter si les liaisons ne séparent plus la norme
de règles rivales, comme la graisse la plus proche. Leurs contrôles négatifs
rejettent chaque observation volontairement fausse.

Sept écarts sont affirmés au lieu d’être masqués ; un oracle échoue si l’un d’eux
disparaît. Le premier est commun aux deux navigateurs et s’écarte d’un exemple
de CSS1 ; les six autres sont des différences avec les témoins navigateur :

- `larger` et `smaller` multiplient ou divisent la taille héritée par 1,2, dans
  le convertisseur comme dans les deux navigateurs. L’exemple de CSS1 où `larger`
  depuis `medium` vaut `large` n’est pas reproduit.
- Une famille sans face du style demandé est sautée, comme l’exigent CSS1 5.2.1
  et CSS 2.1 15.2. Les deux navigateurs restent dans la première famille.
- Les faces étiquetées italique et oblique restent distinctes, comme dans l’un
  des navigateurs. L’autre les apparie comme un seul ensemble.
- Les petites capitales sont synthétisées à 0,8 du corps, comme dans l’un des
  navigateurs. L’autre utilise 0,7.
- Une face étiquetée comme police de petites capitales est dessinée telle
  quelle. `@font-face` ne possède pas cette étiquette : les deux navigateurs
  synthétisent depuis les autres faces de la famille.
- La fonction OpenType `smcp` n’est pas utilisée. Les deux navigateurs dessinent
  les petites capitales d’une police qui en possède.
- `line-height: normal`, rétabli par le raccourci `font`, vaut 4/3 du corps. Les
  deux navigateurs le déduisent des métriques de la police, et CSS1 5.4.8 suggère
  seulement une valeur entre 1,0 et 1,2.

L’oracle ne couvre pas les métriques verticales au-delà de la distance entre deux
lignes de base, l’accord du crénage et des ligatures avec les navigateurs, une
famille par défaut à plusieurs faces, les diagnostics `font.cluster-split` et
`font.small-caps-fallback`, ni la pagination des navigateurs. Aucun gras, italique
ou oblique n’est synthétisé ; `font-size: 0`, l’unité `ex` et un symbole de
caractère manquant restent absents. Ces observations ciblées ne qualifient pas
les propriétés de police dans tous les contextes CSS1.

## Espacements et retrait de première ligne

La version 0.4 ajoute `word-spacing`, `letter-spacing` et `text-indent` à la mise
en page PHP native. Ces propriétés acceptent les longueurs signées prises en
charge et héritent de leurs valeurs calculées. Les espacements acceptent aussi
`normal` ; le retrait admet en plus les pourcentages. Le [guide](usage.md#espacements-et-retrait-de-première-ligne)
précise leurs valeurs et limites.

[Source HTML](../../examples/sources/text-spacing.html) ·
[Générateur](../../examples/generate-text-spacing.php)

![Première page du PDF natif des espacements](../../examples/previews/text-spacing.png)

```css
p { word-spacing: 2px; letter-spacing: .05em; text-indent: 10%; }
p.tight { word-spacing: -1px; letter-spacing: -.02em; text-indent: -12px; }
```

L’exemple fournit explicitement Noto Sans et comprend espacements positifs et
négatifs, espaces insécables, longueurs `em` héritées, accents combinants,
ligatures et retraits de première ligne. Un paragraphe se poursuit également sur
une nouvelle page PDF. Le manifeste conserve les empreintes de la source et de
la police.

```sh
php -d memory_limit=512M examples/generate-text-spacing.php _build/text-spacing
node tools/validate_text_spacing.cjs _build/text-spacing/browser
python tools/validate_text_spacing.py --pdf _build/text-spacing/text-spacing.pdf \
  --manifest _build/text-spacing/manifest.json \
  --browser-report _build/text-spacing/browser/browser-report.json \
  --output _build/text-spacing/pdf
python tools/test_text_spacing_oracle.py
node tools/test_text_spacing_oracle.cjs
```

Installer les dépendances Python verrouillées dans `tools/validation-requirements.txt`,
qpdf, Poppler et les navigateurs indiqués plus bas. Les contrôles indépendants
portent sur positions des glyphes PDF et correspondances Unicode, annotations de
lien, géométrie du texte navigateur et pages rendues. Les témoins navigateur
mesurent une page continue ; ils ne démontrent pas l’équivalence de leur
pagination d’impression avec celle du PDF.

La convention implémentée ajoute l’espacement après chaque graphème, dernier
graphème d’un fragment compris, comme observé dans Chromium et Firefox. Aucun
espace n’est ajouté entre une lettre et ses accents combinants. `normal` et zéro
explicite conservent le même shaping ; un espacement de lettres non nul supprime
les ligatures facultatives. Ces observations ciblées n’annoncent pas CSS Text
complet. Le texte reste sélectionnable et ses avances ajustées servent ensemble
aux retours, au dessin, aux décorations et aux annotations de lien.

Le retrait en pourcentage utilise la largeur de contenu du bloc contenant les
lignes, pas leur largeur temporaire à côté d’un flottant. Le retrait ne recommence
pas après un `br`, un saut de page ou un fragment inline anonyme suivant un bloc
enfant dans le flux. Un bloc descendant possède sa propre règle de première
ligne. Transformation, justification et autres contextes de décoration restent
à réaliser. Un espacement négatif excessif
produisant une avance totale négative est refusé explicitement. Les tabulations
préservées avec un espacement de lettres ou de mots non nul lèvent aussi
`UnsupportedFeature` ; leur profil à espacement normal reste inchangé, sans
annonce de placement exact des taquets CSS.

## Exemple historique de boîtes, flottants et dégagement

La version 0.3 convertit la
[source historique inchangée](https://www.w3.org/Style/CSS/Test/CSS1/current/test5526c.htm)
avec le moteur PHP natif. Elle combine flottants gauche/droite et imbriqués,
largeurs en pourcentage, hauteurs fixes, bordures asymétriques, suppression des
marqueurs, dégagement, fond racine et raccourci de police. Les deux boutons radio
sont des instantanés statiques explicitement demandés.

![Rendu PDF natif du cas historique de boîtes, flottants et dégagement](../../examples/previews/test5526c.png)

[HTML fourni sans modification](../../resources/css1/w3c/test5526c.htm) ·
[Générateur](../../examples/generate-w3c.php) ·
[Empreintes et provenance](../../resources/css1/w3c/provenance.json) ·
[Notice BSD](../../resources/css1/w3c/NOTICE)

Le générateur associe explicitement Lato au générique `sans-serif`, utilise A4
sans marge de page supplémentaire et conserve les marges du HTML. L’URL source
sert seulement de base aux annotations de liens ; aucune URL n’est téléchargée.
Verdana n’étant pas dans le catalogue, le générique déclaré choisit Lato. Le dessin
des glyphes et les retours à la ligne peuvent différer de ceux des navigateurs.

```sh
php -d memory_limit=512M examples/generate-w3c.php _build/w3c
python tools/validate_w3c_css1.py --pdf _build/w3c/test5526c.pdf \
  --manifest _build/w3c/manifest.json --output _build/w3c/validation
npm ci --prefix tools
npm exec --prefix tools -- playwright install chromium firefox
node tools/validate_w3c_css1.cjs _build/w3c/browsers
python tools/test_w3c_css1_oracle.py
node tools/test_w3c_css1_oracle.cjs
```

Les dépendances Python sont figées dans `tools/validation-requirements.txt` ;
qpdf et Poppler sont aussi nécessaires. L’oracle navigateur vérifie 50 coordonnées
et 13 propriétés calculées dans chaque moteur, sur les mêmes octets source à
96 pixels CSS par pouce. L’oracle PDF vérifie empreintes, page A4 exacte unique,
syntaxe qpdf, texte source sélectionnable via CMaps explicites et Poppler,
dégagement/positions du texte et masques de couleurs indépendants à 96 dpi.
Les bords anticrénelés sont exclus sur 1,25 px ; les canaux tolèrent 4/255, avec
au plus 0,2 % d’écarts par couleur. Le masque exclut les zones de texte/contrôles
et la bande finale dépendant des polices ; le contenu textuel et le dégagement
du paragraphe font l’objet de contrôles distincts.

Ce cas historique choisi ne qualifie **ni toute la suite ni CSS1**. Les flottants
exigent une largeur explicite et un document d’une page ; leur pagination,
combinaison avec tableaux et fusion parent-enfant des marges restent absentes.
Les blocs ordinaires décorés chevauchant un flottant antérieur et les flottants
introduits après du texte sur la même ligne sont aussi refusés. La
[couverture](coverage.md) et le [guide](usage.md) précisent ces limites.

## Côtés de bordure indépendants

La version 0.2 ajoute quatre côtés de bordure indépendants et huit styles
visibles. Cet exemple original présente `solid`, `dotted`, `dashed`, `double`,
`groove`, `ridge`, `inset` et `outset`, puis une boîte dont chaque côté possède
son style, sa largeur et sa couleur. Le texte reste sélectionnable ; les
bordures sont des artefacts PDF.

![Styles de bordure CSS1, côtés indépendants et bornage RGB](../../examples/previews/css1.png)

[Source HTML](../../examples/sources/css1.html) · [Générateur](../../examples/generate.php) ·
[PDF de la version 0.2.0](https://github.com/PDFToolkit/html-to-pdf/releases/download/v0.2.0/css1.pdf)

```css
.sides {
    border-top: 4pt solid #26788b;
    border-right: 8pt dashed #3b7557;
    border-bottom: 6pt double #ba5e24;
    border-left: 12pt dotted #78508e;
}
.rgb {
    color: rgb(-20,128,260);
    border: medium solid;
}
```

La dernière boîte borne ses composantes à `rgb(0,128,255)`. La couleur de bordure
omise utilise `currentColor`. Le module choisit `medium = 3px = 2,25pt` ; `thin`
et `thick` utilisent 1px et 5px. Ces dimensions relèvent de l’implémentation.
`none` n’utilise aucune largeur géométrique ; une bordure transparente de style
visible conserve sa largeur. Les bordures inline et les bordures fusionnées de
tableau restent absentes.

La version valide aussi les valeurs originales avant cascade. En Report, une
valeur ultérieure invalide n’efface plus la valeur valide précédente et un
raccourci invalide n’applique aucun côté partiellement. Le [guide](usage.md)
décrit les imports ordonnés et bornés dans un catalogue explicite ainsi que les
identifiants de sélecteurs échappés. Cet exemple visuel se concentre sur les
bordures/couleurs ; il ne démontre pas tous les cas d’analyse ou d’import.

Pour reproduire les exemples et contrôler leurs sorties :

```sh
php -d memory_limit=512M examples/generate.php _build/examples
python tools/validate_pdf.py --directory _build/examples
```

Les preuves de release comprennent les contrôles PDF indépendants, les rendus
de chaque page et les témoins Chromium/Firefox de styles calculés et de géométrie.
Elles identifient le candidat exact et les versions des outils. Un aperçu ne
prouve ni l’équivalence navigateur/PDF ni une certification d’accessibilité.

Le [registre](../../resources/css-coverage.json) compte 54 exigences partielles
et 8 absentes. **CSS1 reste incomplet** ; les prochaines étapes sur le texte et
les polices figurent dans la [progression](css-levels.md). Aucune conformité
PDF/A ou PDF/UA n’est annoncée.
