# Divengine Traceability

Semantic trace links between engineering artifacts and knowledge items.

## Responsibilities

- Define the **TraceLink** content entity.
- Provide semantic link types as `trace_link_type` configuration (18 seeded types, admin UI at
  `/admin/divengine/trace-link-types`), each with allowed source/target patterns and an
  optional same-kind rule.
- Reject unknown types, disallowed ends and duplicate links; delete links with either end.
- Support revision tracking and content moderation.
- Enable JSON:API access for trace querying.

## Proposed Trace Link Model

| Field | Type | Description |
|---|---|---|
| id | integer | Primary identifier |
| uuid | uuid | Universally unique identifier |
| source_entity_type | string | Entity type of the source |
| source_entity_id | integer | Entity ID of the source |
| target_entity_type | string | Entity type of the target |
| target_entity_id | integer | Entity ID of the target |
| link_type | list_string | Semantic link type |
| rationale | string_long | Why this link exists |
| confidence | list_string | Confidence level |
| status | boolean | Published state |
| reviewed_by | entity_reference | Reviewer (user) |
| reviewed_at | timestamp | Review timestamp |
| uid | entity_reference | Created by (user) |
| created | timestamp | Creation time |
| changed | timestamp | Last edit time |

## Initial Trace Link Types

- derives_from
- satisfies
- realizes
- validates
- tests
- depends_on
- conflicts_with
- mitigates
- impacts
- documents
- supersedes
- references

## Dependencies

- divengine_core
- divengine_artifact
- divengine_knowledge
- Drupal core: text, options, user, content_moderation

## Future Iterations

- Dynamic source/target entity reference fields (technical spike with DER).
- Trace matrix Views for gap analysis.
- Trace validation rules (e.g., every requirement must have a test).
- Graph visualization of trace networks.
- Automated trace suggestion based on text analysis.
