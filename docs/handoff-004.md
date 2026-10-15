# Handoff Report 004 — Phase 1: Modeler Platform, BPMN and UML

## Summary

Studio now has a diagram platform and its first two notations, one of each family:
BPMN (canvas, bpmn-js) and UML (text, PlantUML rendered in the browser). Diagram
elements link to any Drupal record; records show the diagrams that reference them; a
diagram can be a trace link end. 112 kernel tests (1488 assertions) and 12 JavaScript
tests pass. Installed on the running site; existing data untouched.

## Modules

### `divengine_modeler` (platform)
- `diagram` content entity (revisionable, moderated, owner, published) bundled by
  `diagram_type` (config: label, description, notation plugin, new revision default).
  No data table: diagrams are not translatable, so Views and content moderation use the
  base table directly.
- `ModelerNotation` plugin type (PHP attributes) with `ModelerNotationBase`: hardened XML
  parsing (no DTD, no entities, no network), size limit, entity URI check.
- Entity constraint `ValidDiagramModel`: source (via the notation), preview SVG and
  element bindings.
- Workflow `diagram_lifecycle`; every new diagram type joins it automatically.
- `DiagramModelSaver`: one new revision per save from the latest revision; approved or
  deprecated diagrams reopen as a draft; conflict detection by base revision.
- `SvgSafety`: rejects scripts, foreignObject, iframes, animation elements, event
  handlers, script/data/protocol-relative URLs, remote references outside links and
  external CSS; 5 MB limit.
- `PreviewBuilder` + preview route with CSP `default-src 'none'`, `nosniff`, private
  caching, `no_cache`; links only to targets the viewer can see, restricted otherwise.
- `LinkIndex` (`diagram_element_link`), backlinks pseudo-field and block, picker endpoints
  (search, resolve, go), "Linked elements" table with "Record a trace".
- Shared editor shell `js/modeler.js` and the entity picker dialog.
- Views listing `divengine_diagrams`; per-type permissions; own/any edit and delete.

### `divengine_modeler_bpmn`
- bpmn-js 18.30.1 vendored byte-identical (integrity checked against npm).
- Links as `divengine:entity` / `divengine:label` extension attributes (moddle extension),
  context pad entry, badges, undoable; SVG export without DOCTYPE, linked elements marked.

### `divengine_modeler_uml`
- `@plantuml/core` 1.2026.8 vendored byte-identical (MIT since 1.2026.6), CodeMirror 6
  bundle built reproducibly from `js/vendor/codemirror/build` (`npm ci && npm run build`
  gives the same hash).
- Source checks refuse every include, remote theme, JSON loading, remote image and script
  link: rendering never fetches anything.
- Semantic keys from the rendering (`entity:`, `link:`, `participant:`); ambiguous or
  missing keys are reported, never guessed. Native `[[entity:…]]` links are indexed.
- The engine repeats the SVG namespace attribute on the root; the editor removes the
  duplicate so the server can parse it.

### `divengine_traceability`
- The trace link form accepts artifacts, diagrams and nodes as ends (kind selector + one
  autocomplete per kind) and can be prefilled with `?source=diagram/4&target=artifact/7`.

## Verification on the site

- Installed with `drush pm:install divengine_modeler divengine_modeler_bpmn
  divengine_modeler_uml` after a database dump.
- Over HTTP as admin: listing, add page, diagram page, both modelers, types, permissions;
  save endpoint (403 without CSRF token, 200 saved, 409 on a stale revision, 422 with an
  unsafe SVG); preview headers and per-viewer links (403 anonymous, 404 wrong hash);
  picker search/resolve/go; backlinks on the artifact page; trace link diagram → artifact
  saved through the form.
- Demo content created for this check: diagrams 1 "Billing classes" (UML, element
  `billing.Invoice` linked to artifact 1) and 2 "Order to cash" (BPMN), and trace link 2
  (diagram 1 realizes artifact 1).

## Known limitations

- No real-browser test yet: the editors are tested in jsdom with stubbed SVG geometry,
  so layout, zoom and drag behaviour are not covered.
- UML keys: sequence messages, activity nodes and other untagged elements cannot be
  linked yet; sequence participants are clickable on their lifeline only.
- The PlantUML standard library (`!include <C4/…>`) is not available.
- Link target types are configured in `divengine_modeler.settings` without a UI.
- Carried over: `composer audit` advisories (Guzzle); artifact and trace link still
  declare unused data tables.

## Next

Phase 2: embedding (`<drupal-diagram>` in CKEditor 5, filter, usage table).
