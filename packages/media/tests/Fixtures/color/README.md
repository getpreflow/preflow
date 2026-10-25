# Colour fixtures

128×64 JPEGs of eight 32×32 patches (four columns, two rows), used by
`tests/Image/ColorManagementTest.php`:

- `cmyk-profile.jpg`: CMYK with FOGRA39L embedded;
- `cmyk-no-profile.jpg`: the same pixels without a profile (read as the default CMYK profile);
- `adobe-rgb.jpg`: RGB values tagged as Adobe RGB (1998);
- `srgb.jpg`: the same RGB values tagged as sRGB (its variants are made by GD as before, byte for byte);
- `references.json`: each fixture's patch values in sRGB (0–255) at the patch centres
  (`centres`, as fractions of width and height);
- `AdobeRGB1998.icc`: colord's Adobe RGB compatible profile (CC0 1.0, see
  `resources/icc/README.md`), only for making `adobe-rgb.jpg`.

## Making them again

Run by hand, with the ImageMagick 7 CLI (`magick`, built with lcms) on the path:

```sh
php packages/media/tests/Fixtures/color/make-references.php
```

It writes the four JPEGs and `references.json`. The references are converted by the
ImageMagick CLI with relative colorimetric intent and black-point compensation into
`resources/icc/sRGB.icc`, the settings `ColorManagement` promises, without the package's
own code. The patch values are listed at the top of the script.

When `srgb.jpg` changes, update the pinned hashes in `ColorManagementTest::SRGB_HASHES`
with the variants GD makes of it (widths 64 and 128, quality 75).
