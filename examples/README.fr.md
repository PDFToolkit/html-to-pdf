# Exemples natifs reproductibles

[English](README.md) · [Français](README.fr.md)

- [Facture](../docs/fr/invoice.md) : accents, colonnes et montants.
- [Catalogue](../docs/fr/catalogue.md) : trois images originales, retours et alternatives.
- [Plusieurs pages](../docs/fr/multipage.md) : trente lignes, en-têtes répétés comme artefacts et saut forcé.

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
