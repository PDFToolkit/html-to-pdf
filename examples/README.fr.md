# Exemples natifs reproductibles

[English](README.md) · [Français](README.fr.md)

- [Facture](../docs/fr/invoice.md) : accents, colonnes et montants.
- [Catalogue](../docs/fr/catalogue.md) : trois images originales, retours et alternatives.
- [Plusieurs pages](../docs/fr/multipage.md) : trente lignes, en-têtes répétés comme artefacts et saut forcé.

- [Bordures CSS1](../docs/fr/css1.md) : huit styles, quatre côtés indépendants et couleurs RGB.

```sh
php -d memory_limit=512M examples/generate.php _build/examples
python tools/validate_pdf.py --directory _build/examples
```

Le générateur lit explicitement les polices/images fournies et écrit les PDF.
La bibliothèque n’effectue aucun accès fichier/réseau. Le HTML reste sous
`sources/` et les licences Lato accompagnent les polices. `tools/build_images.py`
reproduit les illustrations originales MIT.

Le manifeste conserve empreintes entrée/sortie, version PHP, diagnostics, durée
et pic mémoire. Le rapport indépendant conserve les contrôles qpdf, pypdf,
Poppler et les empreintes de toutes les pages rendues. Les artefacts de release
contiennent PDF et rapports. Les aperçus versionnés montrent la première page.

Le [cas historique CSS1 avec flottants](../docs/fr/css1.md) possède son générateur :

```sh
php -d memory_limit=512M examples/generate-w3c.php _build/w3c
python tools/validate_w3c_css1.py --pdf _build/w3c/test5526c.pdf \
  --manifest _build/w3c/manifest.json --output _build/w3c/validation
node tools/validate_w3c_css1.cjs _build/w3c/browsers
```

Sa source reste inchangée sous `resources/css1/w3c/`, avec notices BSD et
empreintes. Le manifeste identifie aussi la police de remplacement explicite et
la politique radio statique. La sortie est une page A4 exacte sans marges de page
supplémentaires ; les marges source restent appliquées. Le guide lié détaille
l’installation des navigateurs et les exclusions de l’oracle. Cet exemple ne
termine pas la qualification CSS1.
