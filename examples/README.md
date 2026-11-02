# Reproducible native examples

[English](README.md) · [Français](README.fr.md)

- [Invoice](../docs/invoice.md): accented text, columns and totals.
- [Catalogue](../docs/catalogue.md): three original images, wrapping and alternatives.
- [Multiple pages](../docs/multipage.md): thirty rows, repeated header artifacts and forced break.

- [CSS1 borders](../docs/css1.md): eight styles, four independent sides and RGB colors.

```sh
php -d memory_limit=512M examples/generate.php _build/examples
python tools/validate_pdf.py --directory _build/examples
```

The generator explicitly reads bundled fonts/images and writes PDF bytes. The
library itself performs no file/network access. Source HTML remains under
`sources/`; Lato licenses are retained beside the fonts. `tools/build_images.py`
reproduces the original MIT illustrations.

The generated manifest records input/output hashes, PHP version, diagnostics,
time and peak memory. The independent report records qpdf, pypdf and Poppler
checks and hashes for every page render. Release assets contain the PDFs and
validation reports. The committed previews show the first page of each example.
