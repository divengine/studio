# Divengine Artifact

Engineering artifact management — requirements, decisions, risks, test cases, evidence and more.

## Responsibilities

- Define the **Artifact** content entity with bundle support.
- Provide the **ArtifactType** configuration entity for managing artifact types.
- Support revision tracking and content moderation.
- Enable JSON:API access for API-first usage.

## Proposed Artifact Entity Model

| Field | Type | Description |
|---|---|---|
| id | integer | Primary identifier |
| uuid | uuid | Universally unique identifier |
| type | entity_reference | Bundle reference to ArtifactType |
| title | string | Artifact title |
| artifact_key | string | Machine-readable key (e.g., REQ-001) |
| summary | string_long | Brief summary |
| body | text_long | Full description |
| status | boolean | Published state |
| uid | entity_reference | Owner (user) |
| created | timestamp | Creation time |
| changed | timestamp | Last edit time |

## Initial Artifact Types

- requirement
- use_case
- decision
- risk
- test_case
- evidence
- knowledge_reference
- method_fragment
- standard_reference

## Dependencies

- divengine_core
- Drupal core: text, options, user, content_moderation

## Future Iterations

- Custom form modes and display modes per artifact type.
- Field-level configuration per bundle.
- Artifact relationships and hierarchy.
- Import/export from external requirements tools.
- Template artifacts and method-driven generation.
