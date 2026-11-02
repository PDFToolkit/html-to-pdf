# Historique

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## Non publié

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
