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

## Codes

An artifact's code (`artifact_key`, for example `REQ-0042`) is permanent once assigned
(ADR-0007):

- New artifacts get the next code of their type's prefix from a global, locked sequence;
  the code field is unique.
- The edit form does not offer the code of a type with a prefix. A save through the
  Entity API that changes it is refused, and validation reports it on the field, so
  writers that validate (JSON:API, when writes are enabled) get a violation.
- New revisions keep the code, and reverting to an older revision keeps the current one.
- There is no administrative exception: a wrong code is fixed by creating a new artifact
  and relating it to the old one (for example with `supersedes`).

Covered by `ArtifactKeyStabilityTest` and `UniqueArtifactKeyConstraintTest`.

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
