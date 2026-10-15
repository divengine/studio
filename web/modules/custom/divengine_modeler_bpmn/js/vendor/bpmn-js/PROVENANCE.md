# Vendored bpmn-js

- Package: `bpmn-js` 18.30.1 (npm, published 2026-09-24)
- Tarball: `https://registry.npmjs.org/bpmn-js/-/bpmn-js-18.30.1.tgz`
- npm integrity: `sha512-xQawvly/8bL9fIW14+RpYh2w+8eFUXDSgiCi/+YjBk3IxT3ZUIiDPZbDhDZjZqNuHSTeZ4J/n3vWjSYh+Y3eXQ==`
- License: bpmn.io license (MIT with the watermark clause), copied to `LICENSE`.

Files are byte-identical to the package:

| File here | File in the package |
|---|---|
| `bpmn-modeler.production.min.js` | `dist/bpmn-modeler.production.min.js` |
| `assets/diagram-js.css` | `dist/assets/diagram-js.css` |
| `assets/bpmn-js.css` | `dist/assets/bpmn-js.css` |
| `assets/bpmn-font/css/bpmn.css` | `dist/assets/bpmn-font/css/bpmn.css` |
| `assets/bpmn-font/font/*` | `dist/assets/bpmn-font/font/*` |

The bpmn.io watermark must stay visible in every editor and viewer (license
clause). Never edit these files; Divengine behaviour lives in
`../../bpmn-notation.js`.

To upgrade: `npm pack bpmn-js@<version>`, compare the tarball's sha512 with
`npm view bpmn-js@<version> dist.integrity`, copy the files above and update
this note and the library version in `divengine_modeler_bpmn.libraries.yml`.
