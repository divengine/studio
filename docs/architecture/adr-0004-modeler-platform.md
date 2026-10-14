# ADR-0004: Modeler Platform for Engineering Diagrams

## Status

Proposed

## Context

Engineers need to model processes, decisions, cases, interaction flows, enterprise
architecture and free-form sketches next to the artifacts they trace. The first notations
are:

| Notation | Engine | License |
|---|---|---|
| BPMN 2.0 | bpmn-js | bpmn.io (MIT + visible watermark) |
| DMN 1.3 | dmn-js | bpmn.io |
| CMMN 1.1 | cmmn-js | bpmn.io |
| IFML | ifml-js | MIT |
| ArchiMate | archimate-js, fork at github.com/rafageist/archimate-js | MIT |
| Free-form | Excalidraw | MIT |

These engines do not agree on a diagram-js version, and the ArchiMate engine is a fork.
A previous implementation (Odoo) proved a design where a shared platform module provides
contracts and services while each notation module ships its own engine.

## Decision

### Modules

- **`divengine_modeler`** — the platform. It never imports a diagram engine.
- **`divengine_modeler_<notation>`** — one module per notation: `bpmn`, `dmn`, `cmmn`,
  `ifml`, `archimate`, `excalidraw`. Each ships its engine as its own Drupal library, its
  editor, its off-screen preview renderer and its link extractor.

### Storage

- One content entity, **`diagram`**, bundled by **`diagram_type`**. Each notation module
  installs its bundle as config. One entity type gives every notation the same listing,
  search target, trace target, access model and revision history.
- Base fields: `label`, `description`, `source` (the canonical document: XML for the
  bpmn.io family, IFML and ArchiMate; scene JSON for Excalidraw), `preview_svg`,
  `preview_hash`, `uid` (owner), revision metadata, `status`.
- **Revisions and content moderation replace copy-based versions.** Every save is a
  revision; the workflow is draft → review → approved → archived. Approved diagrams are
  not edited in place: editing creates a new draft revision.

### Notation plugins

A `ModelerNotation` plugin type (PHP attribute discovery), one plugin per notation module:

- `id`, `label`, `icon`, `diagram_type` bundle, `source_format` (`xml` | `json`),
  `editor_library`, `preview_library`, `default_source()`.
- `validateSource(string $source): array` — structural checks (for example a BPMN
  `definitions` root), using a hardened XML parser (no entities, no network).
- `extractLinks(string $source): array` — element id → entity URI pairs (ADR-0005).
- `extractSearchText(string $source): string` — names and documentation for search.

The platform finds notations through the plugin manager and never names them.

### Editing

- A full-screen editor route per diagram, `/diagram/{diagram}/edit-model`, rendered by the
  notation's editor library. The library loads lazily, only on that route.
- Save goes through one platform controller, `POST /diagram/{diagram}/model`, with a CSRF
  token and `update` access. It receives the source and the SVG exported by the engine,
  validates both, writes a new revision and rebuilds the link index in one transaction.
  A failed SVG export never blocks saving the source.
- Shared front-end helpers (undo/redo shortcuts, fit-to-viewport, dirty guard, save
  client) live in the platform and use only the public API common to every engine.

### Preview

- The SVG is produced by the engine in the browser and stored in `preview_svg` after a
  server-side safety check (no `script`, `foreignObject`, `iframe`, `object`, `embed` or
  `on*` attributes; size limit).
- Served by `GET /diagram/{diagram}/preview/{hash}.svg` with
  `Content-Security-Policy: default-src 'none'`, `X-Content-Type-Options: nosniff` and
  private caching; `?download=1` adds a download disposition.
- Diagrams created without the editor (import, API) have no preview. The first viewer with
  `update` access renders it off-screen in the browser and stores it.

### Embedding

- A CKEditor 5 plugin inserts `<drupal-diagram data-diagram-uuid="…">`, and a text format
  filter renders it as the stored preview with clickable linked elements (pattern of core's
  `<drupal-media>`).
- The platform keeps a usage table (diagram ↔ host entity and field), rebuilt when a host
  is saved, so a diagram shows where it is embedded and deletions can clean up.

### Access

- `view diagrams`, `create diagrams`, `edit own diagrams`, `edit any diagram`,
  `delete own diagrams`, `delete any diagram`, `administer diagrams`, plus per-bundle
  variants. Sharing finer than permissions is out of scope for now.

### Third-party code

- Engines are vendored as built bundles under each module's `js/vendor/<engine>/`, with
  their LICENSE file and a provenance note (upstream, version or commit).
- The bpmn.io watermark stays visible in bpmn-js, dmn-js and cmmn-js editors.
- No CDN: the platform must work offline and behind firewalls.

## Consequences

### Positive

- Adding a notation is a module with one plugin, one bundle and one library.
- Each engine upgrades independently; the ArchiMate fork never constrains BPMN.
- Diagrams join traceability and embedding with no notation-specific code.

### Negative

- Vendored bundles grow the repository and must be updated by hand.
- Browser-side SVG export means previews depend on a browser having opened the diagram.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| One entity type per notation | Repeats storage, access, revision, trace and embed code six times. |
| A shared diagram-js build for all notations | Engines pin different diagram-js versions; the ArchiMate fork would block upgrades. |
| Server-side rendering (headless browser) | Heavy runtime dependency; the engines already export SVG in the browser. |
| Contrib BPMN.iO module | Covers one notation and its own workflow model; does not fit a multi-notation platform. |

## Related Decisions

- ADR-0002: Artifact and TraceLink domain model.
- ADR-0005: Links between diagram elements and Drupal entities.
