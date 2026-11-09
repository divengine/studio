# Divengine Studio Roadmap

Studio becomes an engineering suite on Drupal core: explicit artifacts and traces, diagram
modelers whose elements link to engineering records, and a Unified Process layer that
governs the work. Each phase ends with kernel tests passing and the site installable from
config.

## Status (2026-11-02)

The phases below describe how the core was built. Since Phase 4, Studio has also gained:

- test management (ADR-0008);
- projects and timesheets (ADR-0009, ADR-0010);
- knowledge articles and discussions (ADR-0011);
- Code Source (ADR-0012), database schemas (ADR-0013) and code documentation;
- the web installer and the base recipe for updates (ADR-0014, ADR-0015);
- MCP access with OAuth (ADR-0016, ADR-0017);
- external references to GitHub work (ADR-0018);
- MCP engineering writes and administrator-configured content (ADR-0019, ADR-0020);
- separate rights to take part in a Development and to manage it (ADR-0021).

**Releases.** The first pre-release, `0.1.0-alpha1`, freezes that scope: no new features,
only documentation, licensing and checklist fixes. `0.2.0-beta1` is about upgrades,
access and approval policy (#90), acceptance of the engineering traceability pilot (#72)
and release hardening. Both are GitHub milestones; see
[releasing.md](releasing.md) for the stage criteria.

Phases 3 and 5 below, and the roadmap epics on GitHub (#27–#32, #38–#56), are after beta
unless a milestone says otherwise.

## Phase 0 — Foundation cleanup ✅

Done; see [handoff-003](handoff-003.md).

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

## Phase 1 — Modeler platform + BPMN + UML (ADR-0004, ADR-0005) ✅

Done; see [handoff-004](handoff-004.md).

BPMN and UML are built together so the platform is proven on both notation families
(canvas and text) from the start.

- `divengine_modeler`: `diagram` entity and `diagram_type`, `ModelerNotation` plugin type,
  save controller, safe SVG preview route, link index, backlinks block, entity picker
  dialog, shared editor helpers, moderation workflow.
- `divengine_modeler_bpmn` as the reference notation: vendored bpmn-js, editor, moddle
  extension for `divengine:entity`, off-screen preview renderer, link extractor.
- `divengine_modeler_uml` as the reference text notation: vendored `@plantuml/core`
  (browser engine, files kept byte-identical to upstream) and CodeMirror, split editor,
  source safety checks (no external includes), semantic-key element bindings, link
  extractor.
- Kernel tests: source validation, link extraction, index rebuild, SVG safety, access.

## Phase 2 — Embedding ✅

Done; see [handoff-005](handoff-005.md).

- CKEditor 5 plugin and `<drupal-diagram>` filter; usage table; "Embedded in" on diagrams.

## Phase 3 — Remaining notations

One module each, in this order: `mermaid`, `dmn`, `archimate` (fork
rafageist/archimate-js), `cmmn`, `ifml`, `excalidraw`. Each repeats the BPMN pattern
(canvas) or the UML pattern (text): plugin, bundle, vendored engine, editor, preview
renderer, link extractor, tests.

## Phase 4 — Unified Process ✅

Done; see ADR-0007 and `web/modules/custom/divengine_process/README.md`. QA and Project
were added after it (see the status above). Deterministic UML generation, Discovery and the
dashboard remain.

The phase covers:

- Business and system model as artifact bundles with workflows (ADR-0002).
- `divengine_process`: process configuration (phase types, disciplines, process roles,
  activity and work product definitions, Development Case with snapshot), Developments,
  phases, iterations, role assignments, activities, work products, change requests,
  milestones.
- Readiness engine with `ReadinessCriterion` plugins: advisory, never blocking, per
  milestone.
- Work products and traces pointing to diagrams.
- Not implemented yet: deterministic UML generation from the model (system and business
  use-case diagrams) with provenance: canonical input, input and output hashes, author, previous generation.
  Regenerating never overwrites a diagram that was edited by hand; it creates a new
  revision instead.

## Phase 5 — Development Pulse

Evidence-based dashboard of one Development: lifecycle, discipline × iteration heatmap,
process signature against a reference emphasis profile, traceability flow with gaps.
Never counts hours.

## Out of scope for now

- Semantic search across diagrams.
- UI design (GrapesJS) and flow/function authoring.
- AI assistance beyond MCP access (which exists: reading, and scoped writes that never
  approve), planned on top of the explicit artifact and trace model.
