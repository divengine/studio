# ADR-0002: Artifact and TraceLink as the Engineering Domain Model

## Status

Proposed

## Context

ADR-0001 chose Drupal core and named this decision as future work. Studio now has two
custom content entities, `artifact` (bundled by `artifact_type`) and `trace_link`
(a polymorphic source → target pointer), and is going to absorb two bodies of prior work:

- A Unified Process model (Developments, phases, iterations, disciplines, business and
  system models, activities, work products, change requests, milestones, readiness).
- Diagram modelers (BPMN, DMN, CMMN, IFML, ArchiMate, Excalidraw) whose elements link to
  engineering records.

Both rest on the same principle: **a trace exists because someone recorded it, never
because the system inferred it.** The question is whether each new concept becomes its
own entity type or a bundle of `artifact`.

## Decision

1. **`artifact` is the single entity for engineering knowledge items.** Requirements,
   system use cases, system actors, business use cases, business actors, business workers,
   business rules, risks, decisions, test cases, evidence and references are
   `artifact_type` bundles. Bundle-specific data lives in bundle fields shipped as config
   (for example a use case's flows, pre/postconditions and scope level).
2. **Lifecycle is content moderation, per bundle.** Each bundle family gets a workflow
   (for example requirement: draft → proposed → approved → baselined, deprecated; use
   case: identified → outlined → detailed → reviewed → baselined, deprecated). A baselined
   artifact is not edited in place: material changes create a new revision that goes
   through the workflow again.
3. **`trace_link` is the only relationship record.** Relationship types are a config
   entity (`trace_link_type`) instead of a hard-coded list, so modules can contribute
   types. Each type declares allowed source and target bundles (or entity types), and the
   validator enforces them. The UP relations are added to the existing set: `refines`,
   `realizes`, `implements`, `verifies`, `derives_from`, `impacts`, `supersedes`,
   `produces`, `consumes`, `represents`.
4. **Integrity rules:** no self-links, no duplicate `(source, type, target)`, both ends
   must exist and be viewable by the author, and links are deleted with either end.
5. **Diagrams are trace targets** like any other entity (see ADR-0004); they are not
   artifacts, because their content is a notation document, not structured fields.
6. **Process structure is not an artifact.** Developments, phases, iterations, milestones,
   activities, work products and change requests are their own entities in a process
   module, because they have operational state, not knowledge content.

## Consequences

### Positive

- One listing, one search target and one access model for every knowledge item.
- Trace queries (gap analysis, matrices, impact) run over two tables, not one per concept.
- New concepts are configuration (a bundle plus fields), not code.

### Negative

- Bundle-specific validation (for example "use case needs a primary actor before
  `detailed`") lives in constraint plugins keyed by bundle instead of in a dedicated class.
- `permission_granularity: bundle` must actually be implemented (per-bundle permissions
  callback and an access handler that honours it).

### Neutral

- `divengine_core` value-object enums for artifact and link types are replaced by the
  config entities.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| One content entity per UP concept (as in the Odoo implementation) | Duplicates storage, access and trace plumbing per concept; the Odoo split came from Odoo's model-per-table design, not from the domain. |
| Dynamic Entity Reference for trace ends | Adds a contrib dependency for what two (entity type, id) pairs already do; kept as a later spike per the contrib assessment. |
| Inferring traces from shared fields (iteration, tag, name) | Contradicts the explicit-traceability principle. |

## Related Decisions

- ADR-0001: Use Drupal core.
- ADR-0004: Modeler platform.
- ADR-0005: Links between diagram elements and Drupal entities.
