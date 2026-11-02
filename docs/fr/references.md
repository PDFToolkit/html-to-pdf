# Références primaires et provenance

[English](../references.md) · [Français](references.md)

Révision du 2 novembre 2026. Cette implémentation est indépendante d’iText.
Aucun code, traduction d’implémentation, test ou visuel publicitaire iText n’est
inclus. Les captures fournies pendant la préparation illustrent le comportement
souhaité ; nos exemples emploient des illustrations originales et des polices
avec leur propre licence.

| Référence | Utilisation et portée |
|---|---|
| [Catalogue PDF Association](https://pdfa.org/product/pdfhtml/) | Identifie pdfHTML comme produit iText, sans constituer une norme HTML vers PDF. |
| [Produit iText](https://itextpdf.com/products/convert-html-css-to-pdf-pdfhtml) et [présentation](https://itextpdf.com/blog/itext-news/pdfhtml-convert-html-rich-smart-pdf-documents) | Inspiration fonctionnelle : HTML/CSS statique, mise en page, images, tableaux et sémantique. Aucune équivalence PDFToolkit annoncée. |
| [Licence iText pdfHTML 6.3.4](https://github.com/itext/itext-pdfhtml-java/blob/25569aa8930386754f142dea3323c38e667b3c38/LICENSE.md) | AGPL-3.0-or-later ou commerciale ; aucun code copié dans cette implémentation MIT. |
| [Analyse HTML WHATWG](https://html.spec.whatwg.org/multipage/parsing.html) | Syntaxe et analyse HTML via la dépendance explicite Masterminds HTML5. |
| [CSS 2](https://www.w3.org/TR/CSS2/) | Concepts de boîtes, texte et tableaux ; seul le profil documenté est implémenté. |
| [CSS1](https://www.w3.org/TR/2008/REC-CSS1-20080411/) | Inventaire historique des propriétés/comportements et bornage RGB ; définitions communes selon les spécifications corrigées. |
| [Erreurs syntaxiques CSS](https://www.w3.org/TR/CSS2/syndata.html#parsing-errors) | Rejet local des déclarations et récupération bornée, sans annoncer tous les cas navigateur. |
| [Imports et cascade CSS](https://www.w3.org/TR/CSS2/cascade.html#at-import) | Ordre des imports, identité relative des feuilles, spécificité et importance. Le catalogue explicite remplace tout téléchargement. |
| [Bordures CSS](https://www.w3.org/TR/CSS2/box.html#border-properties) | Côtés indépendants et raccourcis ; le module précise ses largeurs par mots-clés et contextes admis. |
| [CSS Cascade 5](https://www.w3.org/TR/css-cascade-5/) | Cascade et héritage ; sans prétention de couvrir tous niveaux, couches ou portées. |
| [CSS Values 4](https://www.w3.org/TR/css-values-4/#absolute-lengths) | Conversion absolue : 1in = 96px = 72pt. |
| [CSS Fragmentation 3](https://www.w3.org/TR/css-break-3/) | Sauts de page, veuves et orphelines dans le profil pris en charge. |
| [FAQ PDF balisé](https://pdfa.org/resource/tagged-pdf-q-a/) | Structure logique et accessibilité ; le balisage seul n’est pas une certification. |
| [Contrôles qpdf](https://qpdf.readthedocs.io/en/stable/cli.html#option-check) | Syntaxe et références indépendantes, sans validation complète de sémantique ou d’accessibilité. |

Les fiches PDF Association/iText ne sont pas des normes. Les standards HTML/CSS
dépassent la portée du module. La matrice et les tests définissent les capacités
effectivement livrées.

Les illustrations générées sont originales et sous MIT. Les polices Lato des
exemples conservent la notice OFL-1.1 dans `examples/assets/fonts/LICENCE.txt` ;
la police de test Noto Sans conserve la sienne dans `tests/Fixtures/fonts/OFL.txt`.
La provenance des images de test PDFToolkit publiées est conservée avec les fixtures.
