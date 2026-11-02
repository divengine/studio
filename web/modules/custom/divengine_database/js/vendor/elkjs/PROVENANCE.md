# Vendored elkjs

- Package: `elkjs` 0.12.0 (npm, published 2026-07-17), the JavaScript build of the
  Eclipse Layout Kernel.
- Tarball: `https://registry.npmjs.org/elkjs/-/elkjs-0.12.0.tgz`
- npm integrity: `sha512-YZcKynxVxYoKIOEpywEPwCFdg+BTbxQRNf3pbwdDCvc8O3kQD8bmIwSxKU1eOTVc4Xo+VG9Te+575mlfvOrhEQ==`
- License: `EPL-2.0 OR GPL-3.0-or-later` (package metadata). The shipped `LICENSE.md`
  is the EPL-2.0 text whose Exhibit A names the GNU General Public License v3.0 or later
  as Secondary License. Studio uses it under the GPL-3.0-or-later option.

Files are byte-identical to the package (verified 2026-11-02):

| File here | File in the package |
|---|---|
| `elk.bundled.js` | `lib/elk.bundled.js` |
| `elk-worker.min.js` | `lib/elk-worker.min.js` |
| `LICENSE.md` | `LICENSE.md` |

Never edit these files; Divengine behaviour lives in `../../explorer/`.

To upgrade: `npm pack elkjs@<version>`, compare the tarball's sha512 with
`npm view elkjs@<version> dist.integrity`, check that the license metadata is unchanged,
copy the files above and update this note and the version in
`divengine_database.libraries.yml`.
