# Divengine Modeler

The diagram platform (ADR-0004, ADR-0005). It never ships or imports a diagram engine:
each notation is a separate module (`divengine_modeler_bpmn`, `divengine_modeler_uml`, …).

## What it provides

- **`diagram`** content entity, bundled by **`diagram_type`** (one bundle per notation,
  installed by the notation module). Fields: name, description, `source` (the canonical
  document), `element_bindings`, `preview_svg`, `preview_hash`, `search_text`, owner,
  revisions and revision log.
- **`ModelerNotation`** plugins (`src/Plugin/ModelerNotation` in a notation module):
  family (`canvas` | `text`), source format, editor and preview libraries,
  `defaultSource()`, `validateSource()`, `extractLinks()`, `extractSearchText()`,
  `clientSettings()`. `ModelerNotationBase::parseXml()` parses XML without DTDs, entity
  expansion or network access.
- **Lifecycle**: workflow `diagram_lifecycle` (Draft → In review → Approved → Deprecated).
  Every diagram type joins it when created. Saving an approved diagram from the modeler
  starts a new draft; the approved revision stays the default until the draft is approved.
- **Modeler** at `/diagram/{id}/modeler`, always on the latest revision: shared shell
  (`js/modeler.js`) with save, undo/redo, fit, "Link element", downloads, revision message,
  dirty guard, Ctrl+S, conflict detection and the entity picker.
- **Save endpoint** `POST /diagram/{id}/model` (JSON, `X-CSRF-Token`, `update` access):
  source, exported SVG, bindings and log become one new revision; the link index is
  rebuilt in the same transaction. A missing SVG export never blocks saving the source.
- **Previews** `GET /diagram/{id}/preview/{hash}.svg`: the SVG is checked by `SvgSafety`
  before it is stored, then served per viewer with `Content-Security-Policy:
  default-src 'none'` (inline styles only), `nosniff` and private caching. Linked
  elements become links only when the viewer can see the target. `?download=1` downloads
  it. Diagrams without a fresh preview are rendered off-screen by the first editor who
  opens them.
- **Element links** (`entity:<type>/<id>`): index table `diagram_element_link`, rebuilt
  from the document on every save; links to deleted entities are flagged as broken, the
  document is never rewritten. "Linked elements" table on the diagram page, with a
  "Record a trace" action when `divengine_traceability` is enabled.
- **Backlinks**: "Diagrams referencing this record" as a display component on every
  bundle of the link target types and as a block.
- **Entity picker**: search by label across the link target types
  (`divengine_modeler.settings:link_target_types`, by default artifact, diagram, node,
  taxonomy term, media) and resolution of pasted paths, local URLs or entity URIs, always
  limited to what the user can view.
- **Listing**: Views `divengine_diagrams` at `/admin/divengine/diagrams` (search by name or
  element text, type and state filters).

## Permissions

`view diagrams`, `create diagrams`, `edit own diagrams`, `edit any diagram`,
`delete own diagrams`, `delete any diagram`, revision permissions,
`administer diagrams`, `administer diagram types`, and per-type create/edit/delete
variants. Content moderation also requires a workflow transition permission to edit.

## Adding a notation

1. A module depending on `divengine_modeler`.
2. A `ModelerNotation` plugin.
3. A `divengine_modeler.diagram_type.<id>` config file in `config/install`.
4. An editor library that calls
   `Drupal.divengineModeler.registerNotation(id, {mount, renderPreview})` (contract in
   `js/modeler.js`), with the engine vendored in the module under `js/vendor/`.

## Tests

- Kernel: `vendor/bin/phpunit -c phpunit.xml web/modules/custom/divengine_modeler`
  (uses the test notation in `tests/modules/divengine_modeler_test`).
- JavaScript (Node.js, jsdom): `cd tests/js && npm ci && npm test`.
