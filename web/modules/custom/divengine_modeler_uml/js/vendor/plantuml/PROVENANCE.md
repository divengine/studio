# Vendored PlantUML browser engine

- Package: `@plantuml/core` 1.2026.8 (npm, published 2026-09-06), MIT
  (versions 1.2026.6 and later; earlier versions are GPL-3.0-or-later).
- Tarball: `https://registry.npmjs.org/@plantuml/core/-/core-1.2026.8.tgz`
- npm integrity: `sha512-md2wGuaIJnAq1yKkedSpQE68cLsvZvctwzWHkDAnC3DTVLe/MSd/2vr4ETwr6jtc1av8cdxCohFuW4VGejMHyw==`

Files kept byte-identical to the package (upstream ships some of them with
CRLF line endings; `../.gitattributes` stops git from converting them):

- `plantuml.js` — the engine, an ES module loaded with `import()`.
- `viz-global.js` — Graphviz layout engine, a classic script loaded first.
- `themes.js` — the bundled `!theme` definitions.
- `LICENSE`

## What `viz-global.js` contains

`viz-global.js` is Viz.js 3.24.0 (MIT, Copyright Michael Daines) as shipped inside
`@plantuml/core`. It is not byte-identical to the `viz-global.js` of the `@viz-js/viz`
3.24.0 package. Its header states that it contains other software in object code
(WebAssembly) form:

- **Graphviz** 14.1.1 (`graphvizVersion` in the file), licensed under the
  **Eclipse Public License 1.0**. Source: <https://gitlab.com/graphviz/graphviz>.
- **Expat**, licensed under the MIT license. Source: <https://github.com/libexpat/libexpat>.

The package's `LICENSE` covers PlantUML only. It does not include the EPL-1.0 text or a
source offer for Graphviz. Whether distributing this file with Studio is compatible with
GPL-3.0-or-later has **not been determined**. The `plantuml` library in
`divengine_modeler_uml.libraries.yml` declares MIT and `gpl-compatible: true`, which
does not describe this file. See #89 and `THIRD-PARTY-NOTICES.md`.

Never edit these files; Divengine behaviour lives in `../../uml-notation.js`.

The standard library (`!include <C4/...>` and the sprite libraries) is not
vendored, and the notation rejects every include, so rendering never fetches
anything.

To upgrade: `npm pack @plantuml/core@<version>`, compare the tarball's sha512
with `npm view @plantuml/core@<version> dist.integrity`, copy the files above
and update this note and the library versions in
`divengine_modeler_uml.libraries.yml`.
