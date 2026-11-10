# Historique

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## 0.5.0 — 2026-11-11

- Compléter la grammaire des valeurs des propriétés de police CSS1. `font-size`
  accepte les sept mots-clés absolus, sur une table fixe pour un `medium` de 12pt,
  ainsi que `larger` et `smaller`, qui multiplient ou divisent par 1,2 la taille
  calculée du parent comme le font les deux navigateurs témoins. `font-weight`
  accepte `bolder` et `lighter` selon la table de CSS 2.1 15.6, indépendante des
  polices ; `strong` et `b` valent `bolder`. `font-style` accepte `oblique` et
  `font-variant` accepte `small-caps`. Le raccourci `font` les accepte tous. Une
  taille, une graisse, un style ou une variante non déclarés héritent de la valeur
  calculée elle-même.
- Apparier les polices comme le décrivent CSS1 5.2.1 et CSS 2.1 15.2. Un `FontSet`
  étiquette ses quatre emplacements par position et accepte des `FamilyFace`
  supplémentaires avec une graisse explicite de 100 à 900, un style normal,
  italique ou oblique et une variante petites capitales. L’italique accepte une
  face oblique, les autres styles exigent leur étiquette exacte, une famille non
  liée ou sans le style est ignorée, les graisses absentes suivent l’ordre de
  CSS 2.1 15.6 sans jamais échouer, et la famille par défaut fournit sa meilleure
  correspondance. Un caractère sans glyphe est pris dans la famille suivante, par
  grappe de graphèmes ; les puces de liste suivent le même repli.
- **Changement de comportement :** une face grasse ou italique absente et une
  liste de familles sans aucune liaison ne lèvent plus `UnsupportedFeature`. CSS
  définit ces cas : ils produisent désormais les diagnostics informatifs
  `font.weight-fallback`, `font.style-fallback` et `font.default-family` dans les
  deux politiques, complétés par `font.cluster-split` et
  `font.small-caps-fallback`. Les notes sont dédoublonnées, limitées par code et
  n’épuisent jamais le budget de diagnostics. `FontSet::face()` garde sa recherche
  exacte d’emplacement. Un caractère absent de toutes les faces fournies reste refusé.
- Synthétiser les petites capitales avec les capitales de la face appariée à 0,8
  fois le corps, sauf si l’appelant étiquette une police de petites capitales. Le
  texte source, les espacements, `ActualText` et la table Unicode du PDF restent
  tels qu’écrits ; `ß` dessine `SS`. La fonctionnalité OpenType `smcp` n’est pas
  utilisée.
- `ComputedStyle` reçoit `fontSlant()` et les champs `slant` et `smallCaps` ;
  `Drawing\Text` reçoit `capitals`, `at()` et `detached()`. Chaque segment de texte
  dans une autre face ou un autre corps est une commande `Text` ; les segments
  contigus d’un même lien partagent un seul rectangle d’annotation. Une liste de
  familles est limitée à 64 noms.
- Corriger les en-têtes de tableau répétés : leur copie d’artefact perdait les
  espacements de lettres et de mots, et un bouton radio statique placé dans un tel
  en-tête levait une `LogicException`.
- Ajouter un exemple original de polices avec des liaisons étiquetées par
  l’appelant, des contrôles PDF natifs des programmes incorporés, faces, corps,
  glyphes et avances, des témoins Chromium/Firefox indépendants et des tests
  négatifs des oracles. Sept écarts, dont six avec les témoins navigateur, sont
  affirmés par les oracles et énumérés dans `docs/fr/css1.md` au lieu d’être
  masqués.
- La taille de police nulle, l’unité `ex`, le symbole de caractère manquant et les
  règles de casse propres à une langue restent absents. Le registre compte
  désormais 54 exigences partielles et 8 absentes ; CSS1, PDF/A et PDF/UA restent
  non qualifiés.

## 0.4.0 — 2026-11-09

- Ajouter `word-spacing` et `letter-spacing` hérités avec normal, zéro et longueurs
  signées, ainsi que `text-indent` signé/en pourcentage sur la première ligne
  admissible. Conserver les longueurs relatives calculées lors de l’héritage et
  refuser les déclarations invalides avant cascade.
- Partager les avances ajustées du shaping entre retours, texte PDF, décorations
  inline et annotations de lien. Conserver Unicode et accents combinants ; seules
  les valeurs non nulles d’espacement des lettres désactivent les ligatures
  facultatives, normal/zéro gardant leur shaping. Signaler les avances négatives
  et graphèmes inter-spans non pris en charge.
- Conserver l’éligibilité de première ligne à travers BR, pagination et fragments
  anonymes autour de blocs dans le flux. Calculer le pourcentage du retrait sur
  la largeur de contenu du bloc contenant les lignes, indépendamment des
  exclusions temporaires des flottants.
- Ajouter exemple de texte original, contrôles PDF/Unicode/géométrie natifs,
  témoins indépendants Chromium/Firefox et tests négatifs des oracles. Documenter
  l’avance après le dernier graphème et les limites de comparaison de pagination.
- Actualiser guides FR/EN et registre CSS1 : 52 exigences partielles et 10 absentes.
  CSS1, texte/polices complets, PDF/A et PDF/UA restent non qualifiés.

## 0.3.1 - 2026-11-09

- Mettre à jour la dépendance de développement verrouillée `symfony/filesystem` vers 7.4.23 pour corriger [GHSA-6prc-h956-hxjq](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq) dans l’outillage de style du code.
- Conserver le code de production, les API publiques, les exigences PHP et les versions et références de tous les autres packages verrouillés.

## 0.3.0 — 2026-11-02

- Ajouter les flottants gauche/droite à largeur explicite, contextes d’exclusion
  imbriqués et dégagement, avec budgets cumulés et refus des fragmentations,
  combinaisons avec tableaux et fusions parent-enfant des marges non prises en charge.
  Un document à flottants visibles doit tenir sur une page ; le débordement visible
  d’une hauteur fixe conserve son étendue peinte.
- Ajouter listes CSS de familles, noms/génériques explicitement associés et
  développement atomique du raccourci `font` pris en charge. Variantes/glyphes
  absents restent explicites, sans recherche système ni remplacement par caractère.
  Conserver les interlignes spécifiés.
- Accepter les métadonnées Strict historiques reconnues, nœuds de listes de
  définitions, fonds html/body et liens d’annotation relatifs avec base explicite.
  Refuser les découpages inline/bloc qui perdraient une portée de lien ou de langue.
- Ajouter des instantanés radio explicites avec états coché/désactivé et attributs
  logiques Form/PrintField, sans champ PDF interactif, envoi ou JavaScript.
  Styles/types de contrôle absents restent des diagnostics ou erreurs explicites.
- Conserver HTML et GIF historiques boîtes/flottants/dégagement sans modification,
  avec empreintes et notices BSD. Ajouter générateur natif, témoins géométriques
  navigateur, contrôles indépendants PDF/texte/couleurs et tests négatifs des oracles.
- Actualiser guides FR/EN et registre : 49 exigences partielles, 13 absentes.
  Aucune qualification CSS1 complète, PDF/A ou PDF/UA n’est annoncée.

## 0.2.2 — 2026-11-02

- Présenter le convertisseur natif à partir des spécifications primaires HTML/CSS, de sa couverture implémentée et des preuves de validation. Harmoniser les guides FR/EN et les règles de contribution.
- Préciser l’indépendance de l’implémentation MIT et l’origine des ressources d’exemple, en conservant les limites explicites de couverture CSS et de qualification PDF/A/PDF/UA.
- Correction documentaire uniquement ; conversion, dépendances et API publiques inchangées.

## 0.2.1 — 2026-11-02

- Utiliser le nom Composer canonique `pdftoolkit/pdf:^0.12.2` et les dépendances stables migrées. Conserver les namespaces PHP, API et comportements existants.
- Documenter la migration des exigences applicatives en français et en anglais ; conserver les anciennes versions et leurs preuves.

## 0.2.0 — 2026-11-02

Les artefacts de release conservent les reçus PHP, PDF et navigateurs du candidat
exact ; le contrôle d’achèvement CSS1 reste incomplet.

- Validation des valeurs originales avant cascade auteur : conservation des
  valeurs valides précédentes et refus atomique des raccourcis malformés. Bornage
  des composantes RGB originales sans dépendre d’une sérialisation normalisée.
  Signes positifs RGB acceptés sans changer les positions de source ; directives
  d’encodage non prises en charge ou mal placées refusées avant de modifier l’état du parseur.
- Récupération de certaines erreurs syntaxiques en Report, Reject restant strict ;
  listes de sélecteurs atomiques, identifiants échappés et profondeur d’arbre bornée.
  Les valeurs de déclaration échappées restent absentes.
- Résolution ordonnée de `@import` dans les seuls octets CSS fournis, avec
  `HtmlSource::baseUri` facultatif, diagnostics de cycles et limites cumulées de
  nombre, profondeur et octets. Aucun accès réseau ou fichier implicite.
- Côtés de bordure indépendants, huit styles visibles plus none, réinitialisation
  des raccourcis, héritage, géométrie transparente et rendu en artefacts fragmentés.
  Bordures inline et bordures fusionnées de tableau encore absentes.
- Exemple visuel CSS1 original et guides bilingues à jour. Le registre compte
  désormais 45 exigences partielles et 17 absentes, sans annoncer CSS1 complet.
- Constructeur uniforme historique de ComputedStyle conservé ; borderWidth et
  borderColor compilés projettent le côté haut. borderEdges() expose tous les côtés.

## 0.1.0 — 2026-11-02

Première version indépendante en PHP natif de `pdftoolkit/html-to-pdf`.

- Analyse HTML5, cascade CSS, styles typés, préparation de polices, mise en page,
  dessin et sérialisation séparés ; budgets et état propres à chaque document.
- Unicode sélectionnable, variantes TrueType fournies, préparation OpenType
  mémorisée, ressources PNG/JPEG, balises PDF et liens URI associés.
- Texte bloc/inline, listes, fusion des marges positives, tableaux fixes avec
  en-têtes répétés comme artefacts, sauts explicites, veuves et orphelines.
- Facture, catalogue illustré et inventaire de quatre pages reproductibles,
  contrôles indépendants PDF/Unicode/structure/rendu et témoins navigateur.
- Guides FR/EN, diagnostics précis et progression CSS1 en premier, recensant ses
  53 propriétés ainsi que les comportements transversaux.

Il s’agit d’un profil d’entrée expérimental, **pas de CSS1 ou CSS2 complet**.
La couverture historique CSS2, CSS2.1 et les modules modernes versionnés suivent
la progression de `docs/fr/css-levels.md`. Les certifications PDF/A et PDF/UA
restent hors du profil validé.
