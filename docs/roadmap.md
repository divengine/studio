# Divengine Studio Roadmap

Studio becomes an engineering suite on Drupal core: explicit artifacts and traces, diagram
modelers whose elements link to engineering records, and a Unified Process layer that
governs the work. Each phase ends with kernel tests passing and the site installable from
config.

## Phase 0 — Foundation cleanup

Make the existing modules a solid base before adding on top.

- Revision UI: `RevisionLogInterface` + `RevisionLogEntityTrait`, `RevisionHtmlRouteProvider`
  on `artifact` and `trace_link`.
- Forms: keep core field widgets (no raw FAPI replacements) so values save through
  `ContentEntityForm`; trimming and uniqueness stay in constraints.
- Per-bundle permissions for artifacts (`permission_granularity: bundle` implemented).
- Content moderation workflow installed and attached to artifact bundles.
- Views: artifact and trace link listings as config; declare `views` dependency.
- `trace_link_type` config entity replacing the hard-coded list (ADR-0002), with
  allowed-ends rules, duplicate prevention and cleanup on delete.
- Fix `SIMPLESTET_DB` → `SIMPLETEST_DB` in `phpunit.xml.dist` and docs; align
  `composer.json` license and name.

## Phase 1 — Modeler platform + BPMN (ADR-0004, ADR-0005)

- `divengine_modeler`: `diagram` entity and `diagram_type`, `ModelerNotation` plugin type,
  save controller, safe SVG preview route, link index, backlinks block, entity picker
  dialog, shared editor helpers, moderation workflow.
- `divengine_modeler_bpmn` as the reference notation: vendored bpmn-js, editor, moddle
  extension for `divengine:entity`, off-screen preview renderer, link extractor.
- Kernel tests: source validation, link extraction, index rebuild, SVG safety, access.

## Phase 2 — Embedding

- CKEditor 5 plugin and `<drupal-diagram>` filter; usage table; "Embedded in" on diagrams.

## Phase 3 — Remaining notations

One module each, in this order: `dmn`, `archimate` (fork rafageist/archimate-js),
`cmmn`, `ifml`, `excalidraw`. Each repeats the BPMN pattern: plugin, bundle, vendored
engine, editor, preview renderer, link extractor, tests.

## Phase 4 — Unified Process

Port the ideas of the CodeIntel Unified Process (not its code):

- Business and system model as artifact bundles with workflows (ADR-0002).
- `divengine_process`: process configuration (phase types, disciplines, process roles,
  activity and work product definitions, Development Case with snapshot), Developments,
  phases, iterations, role assignments, activities, work products, change requests,
  milestones.
- Readiness engine with `ReadinessCriterion` plugins: advisory, never blocking, per
  milestone.
- Work products and traces pointing to diagrams.

## Phase 5 — Development Pulse

Evidence-based dashboard of one Development: lifecycle, discipline × iteration heatmap,
process signature against a reference emphasis profile, traceability flow with gaps.
Never counts hours.

## Out of scope for now

- Semantic search across diagrams.
- UI design (GrapesJS) and flow/function authoring.
- UML and Mermaid text notations (to be confirmed).
- AI assistance (planned on top of the explicit artifact and trace model).
