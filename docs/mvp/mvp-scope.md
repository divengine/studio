# Divengine Studio — MVP Scope

## Goal

Deliver a working installable Drupal codebase with the core domain model for
structured engineering knowledge, artifacts, and traceability.

## In Scope

### Structured Engineering Knowledge
- Controlled vocabularies for knowledge domains, source types, and standard bodies.
- Taxonomy-based classification of engineering knowledge.
- Basic knowledge item storage and retrieval.

### Engineering Artifacts
- Custom content entity: `Artifact` with bundle support for multiple artifact types.
- Initial artifact types: requirement, use_case, decision, risk, test_case, evidence,
  knowledge_reference, method_fragment, standard_reference.
- Common base fields: title, summary, description, status, priority, owner, revision.
- Config entity for artifact type definitions.

### Semantic Trace Links
- Custom content entity: `TraceLink` connecting source and target entities.
- Link types: derives_from, satisfies, realizes, validates, tests, depends_on,
  conflicts_with, mitigates, impacts, documents, supersedes, references.
- Rationale, confidence, and review fields.
- Revision support for audit trail.

### Basic Navigation
- Admin UI menu entries for artifact types and trace links.
- Basic Views-based listing pages.

### Basic Validation Gaps
- Configurable status workflows for artifacts.
- Content moderation integration for state transitions.

### Workflow-Ready Architecture
- Workflows and Content Moderation enabled from the start.
- Revision tracking on all content entities.

### API-Ready Entities
- JSON:API integration enabled by default on entities.
- REST Export Views.

## Out of Scope (Post-MVP)

| Feature | Rationale |
|---|---|
| AI/RAG assistance | Requires stable artifact and trace model first. |
| Graph visualization | Nice-to-have; MVP uses tabular Views. |
| BPMN automation | Process engine is a separate concern. |
| Advanced project management | Divengine is not a clone of Jira. |
| SaaS/multitenancy | Single-site MVP only. |
| Full methodology engine | Methodologies will be expressed via artifacts and traces, not a separate engine. |
| Import/export with external tools | Post-MVP integration feature. |
| Custom permission system | Relies on Drupal's core permission system for MVP. |
| Public API / developer portal | JSON:API endpoints exist; developer portal comes later. |

## Key Principles

1. **Explicit over inferred.** Entities and traces are first-class citizens.
2. **Installable.** Modules ship with config install hooks and schema.
3. **No lock-in.** Core APIs only; no dependency on unmaintained contrib.
4. **Iterative.** This scope produces a foundation, not a finished product.
