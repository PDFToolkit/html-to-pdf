# Releasing

[English](RELEASING.md) · [Français](RELEASING.fr.md)

1. Keep commits focused on complete behaviors and their tests. Update both
   languages of the guides, coverage and changelog. Publish only stable runtime
   dependencies, without path/VCS overrides.
2. Run Composer validation, platform checks, audit, PHPUnit, PHPStan and style.
   Generate all examples and run the independent qpdf/pypdf/Poppler oracle.
   Inspect every rendered page; retain tool versions, PDF hashes and reports.
   A passing syntax check is not a PDF/UA or PDF/A certification.
3. Require the exact candidate commit to pass CI on PHP 8.3, 8.4 and 8.5.
4. Confirm the version tag is absent locally and remotely. Create an annotated
   immutable tag at that validated commit and publish a GitHub release with
   the supported profile, limitations and validation evidence.
5. Verify the peeled remote tag SHA and Packagist version/reference. Install the
   exact stable version in a fresh consumer using default Composer repositories
   and no development dependencies, then execute a documented conversion.
6. Update the central documentation from the published version and exact SHA.
   Release the documentation separately; do not release unchanged core packages.

The first version is 0.1.0. Before 1.0, new capabilities increment the minor;
compatible fixes increment the patch. Never move a published tag.
