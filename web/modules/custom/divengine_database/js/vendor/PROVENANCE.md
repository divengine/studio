# Vendored front-end libraries

Unmodified files from npm, loaded only on the schema explorer and schema evolution pages.

| Library | Version | License | Files | Source |
|---|---|---|---|---|
| OWL (`@odoo/owl`) | 2.8.3 | LGPL-3.0 (`owl/LICENSE`) | `owl/owl.iife.min.js` (`window.owl`, with the template compiler) | npm `@odoo/owl@2.8.3`, `dist/owl.iife.min.js` |
| JointJS core (`@joint/core`) | 4.3.3 | MPL-2.0 (`jointjs/LICENSE`) | `jointjs/joint.min.js` (`window.joint`) | npm `@joint/core@4.3.3`, `dist/joint.min.js` |
| ELK (`elkjs`) | 0.12.0 | EPL-2.0 OR GPL-3.0-or-later (`elkjs/LICENSE.md`) | `elkjs/elk.bundled.js` (`window.ELK`, in-thread fallback), `elkjs/elk-worker.min.js` (Web Worker) | npm `elkjs@0.12.0`, `lib/` |

Only the open-source JointJS core is used. To upgrade: `npm pack <package>@<version>`, copy the same
files, and update this table, `divengine_database.libraries.yml` and the `*_VERSION` constants in
`js/explorer/layout_service.js` and `js/explorer/renderer.js`.
