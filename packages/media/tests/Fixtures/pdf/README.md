# PDF fixtures

Used by `tests/Preview/PdfPreviewTest.php`:

- `rgb.pdf`: a 600 × 400 pt page of eight RGB patches (its preview is 1600 × 1067);
- `cmyk-print.pdf`: a 400 × 200 pt page of eight DeviceCMYK patches (the CMYK values of
  `tests/Fixtures/color`) with a PDF/X output intent carrying FOGRA39L
  (`resources/icc/FOGRA39L_coated.icc`, CC0);
- `broken.pdf`: a PDF header, then the file stops in the middle of an object;
- `encrypted.pdf`: `rgb.pdf` encrypted with a user password;
- `references.json`: the CMYK patches in sRGB (0–255), sampled at the patch centres
  (`centres`, as fractions of width and height).

## Making them again

Run by hand, with Ghostscript (`gs`) and the ImageMagick 7 CLI (`magick`, built with
lcms) on the path:

```sh
php packages/media/tests/Fixtures/pdf/make-fixtures.php
```

The script writes the PDFs itself (the encrypted one through Ghostscript's pdfwrite).
The references are converted by the ImageMagick CLI from FOGRA39L into
`resources/icc/sRGB.icc` with relative colorimetric intent and black-point compensation,
the settings the package promises, without the package's own code.
