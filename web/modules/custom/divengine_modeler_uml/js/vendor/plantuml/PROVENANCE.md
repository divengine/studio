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

Never edit these files; Divengine behaviour lives in `../../uml-notation.js`.

The standard library (`!include <C4/...>` and the sprite libraries) is not
vendored, and the notation rejects every include, so rendering never fetches
anything.

To upgrade: `npm pack @plantuml/core@<version>`, compare the tarball's sha512
with `npm view @plantuml/core@<version> dist.integrity`, copy the files above
and update this note and the library versions in
`divengine_modeler_uml.libraries.yml`.
