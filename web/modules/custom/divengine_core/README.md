# Divengine Core

Shared services, interfaces, constants and permissions for Divengine Studio.

## Responsibilities

- Define canonical value objects (ArtifactType, TraceLinkType).
- Declare shared permissions for all Divengine modules.
- Provide reusable interfaces and base classes.
- No business entities live here — only truly shared primitives.

## Permissions

| Permission | Description |
|---|---|
| `administer divengine` | Full administrative access. |
| `access divengine` | Basic access to Divengine content. |
| `view divengine reports` | Access reporting features. |

## Dependencies

- Drupal core ^11

## Related Modules

- divengine_knowledge
- divengine_artifact
- divengine_traceability
