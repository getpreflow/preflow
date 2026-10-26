# Vendored assets

These are prebuilt third-party bundles committed verbatim (no build step in this
repo). Re-fetch from the pinned version when upgrading.

## Trix

- Version: 2.1.19
- License: MIT (Basecamp / 37signals)
- Source:
  - trix.umd.min.js — https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.umd.min.js
  - trix.css        — https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.css
- Fetched: 2026-06-19

## Cropper.js

- Version: 1.6.2
- License: MIT (Chen Fengyuan)
- Source:
  - cropper.min.js  — https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js
  - cropper.min.css — https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css
- Fetched: 2026-10-26
- Used by the Edit image screen (image-editor.js). CSP: no style attributes in its
  markup and no setAttribute('style'); it sizes through element.style. Its two
  element.style.cssText writes (CSSOM, not blocked by a nonce CSP) are in the
  `preview` option (unused) and the iOS natural-size fallback.
