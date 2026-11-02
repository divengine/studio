# Vendored OWL

- Package: `@odoo/owl` 2.8.3 (npm, published 2026-03-30), the Odoo Web Library.
- Tarball: `https://registry.npmjs.org/@odoo/owl/-/owl-2.8.3.tgz`
- npm integrity: `sha512-DbVDXISsfBGV1siB0Em/eXo2P0n7ZwFEe3qoQM3gQ4rlZHCWCSxA2hH+MaHSN0RYBEjxtxAPtwJ227nkhhjqgQ==`
- License: `LGPL-3.0-only` (package metadata). The shipped `LICENSE` contains the
  LGPL-3.0 text followed by the GPL-3.0 text it builds on.

Files are byte-identical to the package (verified 2026-11-02):

| File here | File in the package |
|---|---|
| `owl.iife.min.js` | `dist/owl.iife.min.js` |
| `LICENSE` | `LICENSE` |

Never edit these files; the library is loaded unmodified so that users can replace it
with another version, as the LGPL expects.

To upgrade: `npm pack @odoo/owl@<version>`, compare the tarball's sha512 with
`npm view @odoo/owl@<version> dist.integrity`, copy the files above and update this note
and the version in `divengine_database.libraries.yml`.
