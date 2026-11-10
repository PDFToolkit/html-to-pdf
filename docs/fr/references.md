# Références primaires et provenance

[English](../references.md) · [Français](references.md)

Révision du 10 novembre 2026. Cette implémentation indépendante sous licence MIT
s’appuie sur les spécifications primaires HTML/CSS pour définir son profil pris
en charge. Ses exemples emploient des illustrations originales et des polices
avec leur propre licence.

| Référence | Utilisation et portée |
|---|---|
| [Analyse HTML WHATWG](https://html.spec.whatwg.org/multipage/parsing.html) | Syntaxe et analyse HTML via la dépendance explicite Masterminds HTML5. |
| [CSS 2](https://www.w3.org/TR/CSS2/) | Concepts de boîtes, texte et tableaux ; seul le profil documenté est implémenté. |
| [CSS1](https://www.w3.org/TR/2008/REC-CSS1-20080411/) | Inventaire historique des propriétés/comportements et bornage RGB ; définitions communes selon les spécifications corrigées. |
| [Erreurs syntaxiques CSS](https://www.w3.org/TR/CSS2/syndata.html#parsing-errors) | Rejet local des déclarations et récupération bornée, sans annoncer tous les cas navigateur. |
| [Imports et cascade CSS](https://www.w3.org/TR/CSS2/cascade.html#at-import) | Ordre des imports, identité relative des feuilles, spécificité et importance. Le catalogue explicite remplace tout téléchargement. |
| [Bordures CSS](https://www.w3.org/TR/CSS2/box.html#border-properties) | Côtés indépendants et raccourcis ; le module précise ses largeurs par mots-clés et contextes admis. |
| [Propriétés de police CSS1](https://www.w3.org/TR/2008/REC-CSS1-20080411/#font-properties) | Ordre d’appariement, listes de familles, valeurs de style, variante, graisse, taille et raccourci de la section 5.2. Sa note de statut fait prévaloir CSS 2.1 quand les deux définissent une fonctionnalité. |
| [Polices CSS 2.1](https://www.w3.org/TR/2011/REC-CSS2-20110607/fonts.html) | Étapes d’appariement révisées, ordre de sélection des graisses manquantes et table `bolder`/`lighter` indépendante des polices (15.2 et 15.6). Le symbole de caractère manquant que recommande CSS 2.1 n’est pas dessiné. |
| [CSS Fonts 4, font-size](https://www.w3.org/TR/css-fonts-4/#font-size-prop) | Autorisation de calculer `larger` et `smaller` par un rapport fixe au lieu d’un pas dans la table des mots-clés ; aucune autre partie de ce module n’est revendiquée. |
| [Texte CSS 2.1](https://www.w3.org/TR/2011/REC-CSS2-20110607/text.html) | Définitions communes des espacements hérités et du retrait de première ligne. Les témoins navigateur/PDF délimitent la géométrie implémentée, sans qualification complète du texte. |
| [CSS Cascade 5](https://www.w3.org/TR/css-cascade-5/) | Cascade et héritage ; sans prétention de couvrir tous niveaux, couches ou portées. |
| [CSS Values 4](https://www.w3.org/TR/css-values-4/#absolute-lengths) | Conversion absolue : 1in = 96px = 72pt. |
| [CSS Fragmentation 3](https://www.w3.org/TR/css-break-3/) | Sauts de page, veuves et orphelines dans le profil pris en charge. |
| [FAQ PDF balisé](https://pdfa.org/resource/tagged-pdf-q-a/) | Structure logique et accessibilité ; le balisage seul n’est pas une certification. |
| [Contrôles qpdf](https://qpdf.readthedocs.io/en/stable/cli.html#option-check) | Syntaxe et références indépendantes, sans validation complète de sémantique ou d’accessibilité. |

Les standards HTML/CSS dépassent la portée du module. La matrice de couverture
et les tests définissent les capacités effectivement livrées.

Les illustrations générées sont originales et sous MIT. Les polices Lato des
exemples conservent la notice OFL-1.1 dans `examples/assets/fonts/LICENCE.txt` ;
la police de test Noto Sans conserve la sienne dans `tests/Fixtures/fonts/OFL.txt`.
La provenance des images de test PDFToolkit publiées est conservée avec les fixtures.

Les fichiers historiques `test5526c.htm` et `sec5526c.gif` sont conservés sans
modification pour les tests de développement sous l’option BSD du W3C. Leur
[notice](../../resources/css1/w3c/NOTICE) et leur
[manifeste](../../resources/css1/w3c/provenance.json) gardent URL, date de collecte,
empreintes exactes et références de licence. Le GIF contient l’interface d’un
ancien navigateur ; il ne sert pas d’oracle de pixels sur toute l’image. Ces
preuves ciblées n’annoncent ni conformité à toute la suite W3C ni approbation.
