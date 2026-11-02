# Third-party notices

Divengine Studio is licensed under GPL-3.0-or-later (see [LICENSE](LICENSE)). This
repository also distributes the third-party browser libraries listed below, unmodified,
each with its own license file. Drupal core, contributed modules and other Composer
packages are **not** part of this repository: `composer install` downloads them, each
under its own license.

Audit of 2026-11-02 (#89). Every vendored file was compared byte for byte with the file
in the upstream npm package of the stated version and found identical.

## Bundled browser libraries

| Library | Version | Location | License | Status |
|---|---|---|---|---|
| highlight.js | 11.11.1 | `web/modules/custom/divengine_source/js/vendor/highlightjs/` | BSD-3-Clause | Compatible |
| CodeMirror 6 (15 bundled packages) | 6.0.2 | `web/modules/custom/divengine_modeler_uml/js/vendor/codemirror/` | MIT (`LICENSES.txt`) | Compatible |
| PlantUML (`@plantuml/core`) | 1.2026.8 | `web/modules/custom/divengine_modeler_uml/js/vendor/plantuml/` | MIT | Compatible |
| Viz.js, inside PlantUML's `viz-global.js` | 3.24.0 | same | MIT | Compatible |
| Graphviz, object code inside `viz-global.js` | 14.1.1 | same | EPL-1.0 | **Not determined** |
| Expat, object code inside `viz-global.js` | — | same | MIT | Compatible |
| bpmn-js | 18.30.1 | `web/modules/custom/divengine_modeler_bpmn/js/vendor/bpmn-js/` | bpmn.io license (MIT with a watermark clause) | **Not determined** |
| elkjs | 0.12.0 | `web/modules/custom/divengine_database/js/vendor/elkjs/` | EPL-2.0 OR GPL-3.0-or-later | Compatible (GPL-3.0-or-later option) |
| JointJS (`@joint/core`) | 4.3.3 | `web/modules/custom/divengine_database/js/vendor/jointjs/` | MPL-2.0 | Compatible (MPL-2.0 section 3.3) |
| OWL (`@odoo/owl`) | 2.8.3 | `web/modules/custom/divengine_database/js/vendor/owl/` | LGPL-3.0-only | Compatible |

Each directory has the upstream license text and a `PROVENANCE.md` with the package,
tarball, integrity hash and the files taken from it.

"Compatible" records the license terms and the reason above. It is not legal advice.

## Open questions

These items are **unresolved** and block a release until the maintainer decides them
(#89):

- **Graphviz (EPL-1.0).** EPL-1.0 is generally regarded as incompatible with the GPL and
  has no secondary-license clause. Upstream ships `viz-global.js` without the EPL-1.0 text
  or a Graphviz source offer. Graphviz source: <https://gitlab.com/graphviz/graphviz>.
- **bpmn-js watermark clause.** The license requires that the code displaying the
  bpmn.io watermark is not removed or changed and that the watermark stays visible. It has
  not been determined whether this is an acceptable attribution term under GPL-3.0
  section 7(b).

## Composer dependencies (not distributed here)

For reference, `composer licenses --no-dev` on 2026-11-02:

| License | Packages |
|---|---|
| MIT | 65 |
| GPL-2.0-or-later / GPL-2.0+ | 13 |
| BSD-3-Clause | 6 |
| BSD-2-Clause | 3 |
| GPL-2.0 | 1 |

The single `GPL-2.0` package is `e0ipso/shaper`, which the optional MCP stack pulls in
through `drupal/jsonrpc`. It declares the deprecated `GPL-2.0` identifier (GPL-2.0-only)
with no "or later" statement. Anyone who redistributes a built Studio site that includes
the MCP stack should take this into account.
