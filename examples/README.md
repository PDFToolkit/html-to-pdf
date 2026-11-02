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

The [historical CSS1 float case](../docs/css1.md) has a separate generator:

```sh
php -d memory_limit=512M examples/generate-w3c.php _build/w3c
python tools/validate_w3c_css1.py --pdf _build/w3c/test5526c.pdf \
  --manifest _build/w3c/manifest.json --output _build/w3c/validation
node tools/validate_w3c_css1.cjs _build/w3c/browsers
```

Its source is preserved unchanged under `resources/css1/w3c/`, with BSD notices
and hashes. The manifest also identifies the explicit fallback font and static
radio policy. The output is one exact A4 page with zero additional page margins;
source margins remain in effect. See the linked guide for browser setup and the
precise oracle exclusions. This example does not complete CSS1 qualification.
