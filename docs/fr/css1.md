# CSS1 : bordures indépendantes et couleurs originales

[English](../css1.md) · [Français](css1.md)

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

Le [registre](../../resources/css-coverage.json) compte 45 exigences partielles
et 17 absentes. **CSS1 reste incomplet** ; le texte et les polices constituent la
prochaine étape de la [progression](css-levels.md). Aucune conformité PDF/A ou
PDF/UA n’est annoncée.
