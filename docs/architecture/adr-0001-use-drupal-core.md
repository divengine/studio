# ADR-0001: Use Drupal Core as the Base for Divengine Studio

## Status

Accepted

## Context

Divengine Studio is an engineering platform designed for explicit software engineering,
traceability, technical knowledge management, artifact lifecycle, standards compliance,
evidence collection, validation workflows, and future AI assistance.

Unlike a general-purpose website starter, Divengine Studio requires:
- Fine-grained control over domain entities and relationships.
- Custom content entity types with explicit bundle semantics.
- Structured trace links between engineering artifacts.
- Workflow and moderation support for engineering processes.
- Clean separation of core domain logic from presentation.
- Ability to be packaged and distributed as an installable Drupal recipe or profile.

## Decision

Use **Drupal core only** as the base platform. No Drupal CMS distribution will be used.

Drupal core provides:
- **Entity API** and **Field API** for custom content entities and bundles.
- **Config API** for installable configuration.
- **Views** for listing and filtering.
- **Taxonomy** for controlled vocabularies.
- **Workflows + Content Moderation** for engineering process states.
- **Revisions** for full audit trail.
- **JSON:API** for headless/API-first access.
- **Action system** and **Plugin API** for extensibility.

## Consequences

### Positive

- Full control over the domain model without inheriting preconfigured UX from Drupal CMS.
- Clean, modular architecture with explicit dependencies.
- Smaller maintenance surface — only the features we need.
- Standard Drupal upgrade path.
- Easier to publish as a standalone module/profile on drupal.org.

### Negative

- More initial scaffolding compared to starting with Drupal CMS.
- No prebuilt project management UX — all UI must be built custom.
- Engineers must understand Drupal core APIs deeply (Entities, Config, Plugins).

### Neutral

- The product will need its own theme layer, not inherited from a distribution.
- Contrib module evaluation must be deliberate — no accidental dependency creep.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Drupal CMS as base | Too much preconfigured UX; diverges from engineering platform goals. Overwhelming for the target audience. |
| Project management contrib modules as core domain | Built for generic project management, not engineering traceability. Risk of forcing the domain model into ill-fitting structures. |
| Symfony framework standalone | Would lose all Drupal APIs (Entity, Field, Config, Views). Reinventing too much. |

## Related Decisions

- ADR-0002: Artifact and TraceLink as the engineering domain model.
- ADR-0003 (future): Taxonomy for controlled vocabularies vs. custom config entities.
- ADR-0004: Modeler platform for engineering diagrams.
- ADR-0005: Links between diagram elements and Drupal entities.
