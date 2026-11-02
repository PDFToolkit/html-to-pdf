# Historique

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

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
la progression de `docs/fr/css-levels.md`. Aucune certification PDF/A, PDF/UA ou
équivalence intégrale à iText n’est annoncée.
