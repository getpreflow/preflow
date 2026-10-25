# ICC profiles

Used by `Preflow\Media\Image\ColorManagement` to convert CMYK and wide-gamut images to sRGB.

| File | Use | Source | Licence |
|------|-----|--------|---------|
| `FOGRA39L_coated.icc` | Default CMYK profile, assigned to CMYK images without one (`config/media.php` `color_profile_cmyk` overrides it) | colord 1.4.8, `data/profiles/FOGRA39L_coated.iccprofile.xml` (made with ArgyllCMS from the FOGRA39L characterisation data), as built in Debian's `colord-data_1.4.8-3_all.deb` (`/usr/share/color/icc/colord/FOGRA39L_coated.icc`) | CC0 1.0 |
| `sRGB.icc` | The sRGB target of every conversion | colord 1.4.8, `data/profiles/sRGB.iccprofile.xml`, from the same package | CC0 1.0 |

- Upstream: https://github.com/hughsie/colord/tree/main/data/profiles (each XML says
  `<license>CC0</license>` and "This profile is free of known copyright restrictions").
- Package: http://deb.debian.org/debian/pool/main/c/colord/colord-data_1.4.8-3_all.deb; its
  `copyright` file lists `data/profiles/*` as CC0-1.0.
- `LICENSE` is the CC0 1.0 Universal legal code
  (https://creativecommons.org/publicdomain/zero/1.0/legalcode.txt).

SHA-256:

```
19d6cd1de88bf48816a7d2bfea5b71e3fff65d09ba0bb4b26f58f76cb4840929  FOGRA39L_coated.icc
1bdc23fb14463058a52ac513bf3bff4e3c52fd75c919cfb0da35dd7e71fbded0  sRGB.icc
```

FOGRA39 is the usual European coated-paper offset condition (ISO 12647-2), the condition
most print PDFs and CMYK JPEGs from European printers are made for.
