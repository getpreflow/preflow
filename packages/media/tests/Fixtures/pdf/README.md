# PDF fixtures

Used by `tests/Preview/PdfPreviewTest.php`:

- `rgb.pdf`: a 600 × 400 pt page of eight RGB patches (its preview is 1600 × 1067);
- `cmyk-print.pdf`: a 400 × 200 pt page of eight DeviceCMYK patches (the CMYK values of
  `tests/Fixtures/color`) with a PDF/X output intent carrying FOGRA39L
  (`resources/icc/FOGRA39L_coated.icc`, CC0);
- `cmyk-newsprint.pdf`: the same page with an output intent far from the default CMYK
  profile, colord's SNAP TR002 newsprint (`SNAP_TR002_newsprint.icc`, CC0 1.0, from
  `colord-data_1.4.8-3_all.deb` like `resources/icc`; SHA-256
  `43a91c78bf37b9929e93b67626dc275016b67551cff6cda307362248baa91ece`), so its colours
  only match when the renderer follows the intent;
- `broken.pdf`: a PDF header, then the file stops in the middle of an object;
- `encrypted.pdf`: `rgb.pdf` encrypted with a user password;
- `references.json`: each print PDF's CMYK patches in sRGB (0–255) through its output
  intent's profile, sampled at the patch centres (`centres`, as fractions of width and height).

## Making them again

Run by hand, with Ghostscript (`gs`) and the ImageMagick 7 CLI (`magick`, built with
lcms) on the path:

```sh
php packages/media/tests/Fixtures/pdf/make-fixtures.php
```

The script writes the PDFs itself (the encrypted one through Ghostscript's pdfwrite).
It rewrites `encrypted.pdf` with a new document id each time; keep the committed one
unless the source changes. The references are converted by the ImageMagick CLI from each
output intent's profile into
`resources/icc/sRGB.icc` with relative colorimetric intent and black-point compensation,
the settings the package promises, without the package's own code.
