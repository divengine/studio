# Handoff Report 003 — Phase 0: Foundation Cleanup

## Summary

The existing modules are now a solid base for the modeler platform and the Unified Process
layer: core revisions, a moderated artifact lifecycle, configurable trace link types,
per-type permissions, Views listings, and forms that save through field widgets.
36 kernel tests, 528 assertions, all passing. Applied to the running site with update
hooks; its existing artifacts and trace link were preserved.

## Changes

### Revisions (artifact, trace_link)
- `RevisionLogInterface` + `RevisionLogEntityTrait` replace the hand-written revision
  fields; `RevisionHtmlRouteProvider` adds history, view, revert and delete routes.
- Revision permissions (`view/revert/delete artifact revisions`, per type as well, and the
  trace link equivalents) and the matching access operations.
- Artifact types implement `RevisionableEntityBundleInterface`; new revisions are the default.

### Forms
- `ArtifactForm` and `TraceLinkForm` no longer replace widgets with raw form elements, so
  `ContentEntityForm` saves every field (verified with an HTTP form submission).
- Artifact keys are trimmed in `preSave()` and validated trimmed: `"REQ-001 "` is now a
  duplicate of `"REQ-001"` (it used to be accepted as distinct).
- Trace link ends are copied to the entity before validation, so the entity constraint
  covers the form.
- Fixed a 500 on the artifact type form (machine name without `source`).

### Per-type permissions
- `ArtifactPermissions` generates create/edit/delete and revision permissions for every
  artifact type; the access handler accepts the type-wide or the per-type permission.

### Artifact lifecycle
- Workflow `artifact_lifecycle` (content moderation) on all artifact types:
  Draft → In review → Approved (published) → Deprecated, with "Request changes" and
  "Restore to draft". An approved artifact is changed through a new draft; the approved
  revision stays the default until the draft is approved.
- Editing requires a transition permission
  (`use artifact_lifecycle transition …`), as content moderation enforces.

### Trace link types (ADR-0002)
- New config entity `trace_link_type` with label, inverse label, description, allowed
  source/target patterns (`entity_type` or `entity_type:bundle`), a same-kind rule and a
  weight. Admin UI at `/admin/divengine/trace-link-types`.
- 18 seeded types: the previous 12 plus `refines`, `implements`, `verifies`, `produces`,
  `consumes`, `represents`. Seeds have no end restrictions, so existing links stay valid;
  `supersedes` requires the same kind.
- Validation: unknown type, disallowed ends, same-kind and duplicate
  (source, type, target) are rejected, one violation per problem.
- Trace links are deleted with either end (`hook_entity_delete`).
- `reviewed_at` is set when the reviewer changes and cleared when it is removed.

### Listings
- Views `divengine_artifacts` (key/title search, type and state filters) and
  `divengine_trace_links` (relation and confidence filters) replace the list builders at
  the same paths. Shipped as optional config and covered by a fresh-install test.
- Non-translatable entities never create their declared data tables; a
  `hook_views_data_alter` moves content moderation's Views integration to the artifact
  base table, and declares list filters for trace link relation and confidence.

### Housekeeping
- `divengine_core` value-object enums removed (unused; replaced by config entities).
- `SIMPLETEST_DB` typo fixed in `phpunit.xml.dist` and `docs/development/testing.md`.
- `bootstrap.kernel.php` registers core extension namespaces (core test traits).
- `composer.json`: name `divengine/studio`, license `GPL-3.0-or-later` (lock hash only).
- Declared dependencies: `views`, `workflows`; traceability no longer depends on
  `divengine_knowledge`, which it never used.

## Update path

| Hook | Effect |
|---|---|
| `divengine_traceability_update_11001` | Installs `trace_link_type`, creates the seeded types, updates link type and revision field definitions |
| `divengine_artifact_update_11001` | Updates revision field definitions and the artifact entity type |
| `divengine_artifact_post_update_artifact_lifecycle_workflow` | Installs the workflow on the artifact types the site has |
| `*_post_update_*_listing_view` | Installs the optional Views config |

## Known limitations

- The trace link form edits artifact-to-artifact links only; other ends (diagrams) arrive
  with Phase 1.
- `composer audit` reports 19 advisories in 4 packages (Guzzle and related); not addressed
  in this phase.
- `artifact` and `trace_link` still declare data tables they do not use; removing them
  needs a storage migration and is left for a later, dedicated change.

## Next

Phase 1: modeler platform with BPMN and UML (ADR-0004, ADR-0005).
