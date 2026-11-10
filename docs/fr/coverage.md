# Couverture du profil d’entrée natif

[English](../coverage.md) · [Français](coverage.md)

La version 0.4 étend le moteur natif avec espacements signés du texte et retrait de première ligne. **Elle ne couvre
pas encore intégralement CSS1, CSS2 ni un module CSS moderne complet.** Le travail
suit la [progression par niveaux](css-levels.md). Les capacités de niveaux
ultérieurs déjà présentes restent des extensions explicites ; elles ne comblent
pas les exigences CSS1 absentes.

| Domaine | Profil implémenté | Limites restantes |
|---|---|---|
| HTML | Parseur HTML5 UTF-8 explicite, arbre réparé, titre/langue, titres, paragraphes, conteneurs, emphase, listes de définitions, images, tableaux et liens textuels. Les doctypes HTML4 Strict reconnus restent admis dans la chaîne HTML5. | Pas de JavaScript, contrôles de formulaire généraux, SVG, MathML, médias, remappage ARIA ou mise en page arbitraire d’éléments personnalisés. |
| Cascade | Feuilles auteur, style/link et `@import` ordonnés dans le catalogue, styles inline, spécificité, ordre, `!important`, héritage et mots-clés globaux. Validation des déclarations originales avant cascade ; raccourcis invalides écartés intégralement en Report. | Pas de feuille utilisateur, couches, scope ou propriétés personnalisées. Reject reste strict ; Report récupère certaines erreurs syntaxiques, pas tous les cas navigateur. Valeurs de déclaration échappées encore absentes. |
| Sélecteurs | Type/classe/ID, identifiants échappés, groupes, descendants/enfants/frères et sélecteurs structurels pris en charge par Symfony. Listes validées intégralement et arbres bornés. | Pseudo-éléments et états interactifs/de liens encore refusés ; pas de couverture complète de Selectors. |
| Média | Sélection print/all simple, exclusion des règles screen. | Pas de caractéristiques média ni `@page` ; page configurée par objet explicite. |
| Polices | Vraies variantes regular/bold/italic/bold-italic TrueType statiques, noms de familles ordonnés et génériques explicitement associés, raccourci `font` pris en charge atomiquement, incorporation, GSUB/GPOS et Unicode sélectionnable. | Pas de recherche système, remplacement par caractère, petites capitales, tailles par mots-clés, correspondance relative/complète des graisses, fontes variables, WOFF, CFF, bidi ou scripts complexes. |
| Texte | Normal/nowrap/pre/pre-wrap, sauts explicites, alignement gauche/centre/droite, soulignement, mesure après shaping, espaces insécables, espacements signés des mots/lettres, retrait de première ligne hérité en longueur/pourcentage et interlignes explicites, y compris demi-interligne négatif. | Pas de justification, découpage automatique des mots, césure, transformation du texte, overline/line-through, first-letter/first-line ou vertical-align inline. Espacements en pourcentage, avances totales négatives et espacement de lettres non nul sur un graphème partagé entre spans refusés. Les tabulations préservées avec espacement de mots/lettres non nul lèvent `UnsupportedFeature` ; le profil existant à espacement normal reste inchangé, sans qualification exacte des taquets CSS. Un retrait sans largeur de ligne positive échoue ; qualification complète par valeur/contexte encore ouverte. |
| Couleurs | RGB entier/pourcentage validé et borné, hexadécimal 3/6 chiffres, noms pris en charge, currentColor, fonds/bordures transparents. | Pas d’alpha arbitraire, dégradés, images de fond, couleurs CSS ICC ou toutes les fonctions modernes. |
| Boîtes | Longueurs positives, largeurs en pourcentage, flux bloc/inline, fusion des marges positives entre frères/parents/blocs vides, padding, côtés de bordure indépendants, huit styles visibles plus none, fonds, propagation html/body au fond de page et hauteurs explicites. Le profil borné avec flottants conserve le débordement visible ; le flux ordinaire le refuse encore. | Pas de marges négatives/auto/pourcentage, padding/hauteurs en pourcentage, boîtes inline décorées, positionnement, flex ou grid. |
| Flottants | Largeur explicite, placement gauche/droite, contextes imbriqués, exclusion des lignes, dégagement left/right/both, suppression des marqueurs avec `display:block`. | Tout document contenant des flottants visibles doit tenir sur une page ; pas de fragmentation des flottants, combinaison avec tableaux, largeur intrinsèque ou fusion parent-enfant des marges dans ces contextes. Blocs ordinaires décorés chevauchant un flottant antérieur et flottants introduits après du texte sur la même ligne refusés. |
| Contrôles statiques | Instantanés radio explicites avec état coché/désactivé, apparence vectorielle et attributs logiques Form/PrintField. | Refusés par défaut ; aucun champ PDF, envoi ou JavaScript. Autres contrôles, dimensions/décorations radio personnalisées et radios bloc/flottantes refusés. |
| Tableaux | Colonnes fixes explicites, légendes au-dessus du tableau, lignes rectangulaires, padding/fond de cellule, alignement haut/milieu/bas, scope TH, en-têtes répétés comme artefacts, pagination entre lignes. | Pas de largeur intrinsèque auto, cellules fusionnées, bordures fusionnées, tfoot, groupes de lignes décorés, légende en bas ou découpage d’une ligne trop haute. |
| Listes | Début/valeur des listes ordonnées, marqueurs disc/circle/square/decimal/none, listes imbriquées avec L/LI/Lbl/LBody. | Pas de numérotation romaine/alphabétique, images de marqueur, position inside ou raccourci list-style. |
| Pagination | Page uniforme, sauts forcés et anciens alias, veuves/orphelines, maintien des groupes tenant sur une page. | Groupe avoid trop grand fragmenté avec diagnostic ; ligne/image/ligne de tableau indivisible trop grande refusée. Pas d’en-têtes courants, compteurs ou pages nommées. |
| Images | PNG/JPEG explicites ; proportions intrinsèques ou dimensions imposées, transparence PNG, alternative textuelle/artefact décoratif. | Pas de téléchargement implicite ni prise en charge automatique de formats arbitraires. |
| PDF | PDF 1.7, polices incorporées, métadonnées/langue, arbre de structure, MCR par page, annotations URI avec OBJR, artefacts. | Aucune conformité PDF/A ou PDF/UA annoncée ; pas de champs, signatures ou destinations internes dans ce module. |

Les [exemples CSS1](css1.md) montrent espacements/retraits, bordures et cas historique choisi avec flottants.
Le registre contient 53 entrées `partial` et 9 `unsupported`, aucune entièrement
qualifiée. Les preuves de release identifient le candidat exact et les outils.

Les listes de définitions conservent `dl/dt/dd` dans le modèle source et les
projettent en `Sect/P/Sect` dans la structure PDF, sans annoncer une sémantique
plus riche. Les enveloppes inline simples peuvent être séparées autour de blocs ;
les enveloppes décorées ou portant un lien/langue autour de ces blocs sont refusées.

La géométrie utilise des boîtes de contenu. Les styles par défaut appartiennent
au module et ne promettent pas l’équivalence des styles navigateur. Pour des
modèles reproductibles, préciser tailles, interlignes, marges et tableau fixe.

Les tests couvrent analyse/cascade, mise en page, shaping, écriture et conversion
complète. L’oracle des exemples utilise qpdf, pypdf et Poppler indépendamment.
Les témoins Chromium/Firefox comparent certains styles calculés, sans prétendre
à l’équivalence de toutes les pages imprimées. Les artefacts de release donnent
versions et résultats ; des témoins réussis ne qualifient pas un niveau entier.
