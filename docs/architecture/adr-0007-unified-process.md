# ADR-0007: Unified Process in Studio (port of CodeIntel UP)

## Status

Accepted

## Context

Roadmap Phase 4 brings the Unified Process of CodeIntel (`codeintel_up`, an Odoo 19 addon)
into Studio: Developments with their four lifecycle phases, iterations and milestones;
process configuration (disciplines, process roles, activity and work product definitions)
tailored by Development Cases; the business model (business actors, workers, use cases and
rules) as the predecessor of the system model (system actors, system use cases,
requirements); activities, work products, change requests, explicit traces and advisory
readiness. ADR-0002 already decided the split: knowledge items are artifact bundles,
process structure gets its own entities, and `trace_link` is the only relationship record.

The Odoo addon is the functional reference; we port its semantics, not its code. A full
specification of the addon was extracted before starting (model by model, state machines,
readiness criteria, seed data, security, behaviour confirmed by its tests).

## Decision

### Modules

`divengine_process` holds everything the Unified Process needs that is not a generic
artifact or trace: process configuration, Developments and their operational records,
the business and system model bundles, readiness and the overview. It depends on
`divengine_artifact` and `divengine_traceability`; neither of them depends on it.
Integrations (UML generation, Discovery, QA, Project, dashboard) come later as separate
modules, using the extension points below.

### Process configuration (config entities, seeded as default config)

| Odoo model | Config entity | Key |
|---|---|---|
| Lifecycle Phase Type | `process_phase_type` | code (`inception`, …) |
| Discipline | `process_discipline` | code |
| Process Role | `process_role` | code |
| Work Product Definition | `work_product_definition` | code |
| Activity Definition | `activity_definition` | code |
| Development Case + lines | `development_case` (lines stored inside it) | code |

The Odoo codes become the machine names. A Development Case keeps its tailoring lines in
the config entity itself; every save that changes the lines increments `version` by
exactly one, so "applied version ≠ current version" means the case changed.

### Development structure (content entities)

`development` (code `DEV-0001`), `development_phase`, `iteration`, `milestone`,
`role_assignment`, `process_activity`, `work_product` (`WP-`), `change_request` (`CR-`).
Creating a Development creates one phase per active phase type, one milestone per closing
milestone and applies the default Development Case. The case is **snapshotted** into the
Development and only changes when someone re-applies it.

Operational lifecycles (Development, iteration, milestone, activity, work product, change
request) are state fields changed only through transitions with guards, in code: they are
domain rules (a block needs a reason, an accept needs a rationale, scheduling needs a
target iteration), not editorial workflows.

### Business and system model (artifact bundles)

`requirement` and `use_case` (labelled System Use Case) already exist; `system_actor`,
`business_actor`, `business_worker`, `business_use_case` and `business_rule` are added.
Bundle data (statements, flows, levels, priority and risk with *Unassessed*) are bundle
fields shipped as config. Native links that are part of the record, not traces, stay as
entity reference fields: a use case's actors and requirements, a business use case's
actors, workers and governing rules. Every UP artifact belongs to one Development
(`development` base field added by `divengine_process`); references between artifacts of
different Developments are refused.

Lifecycles are content moderation workflows: `requirement_lifecycle` (draft → proposed →
approved → baselined, deprecated) for requirements and business rules, and
`use_case_lifecycle` (identified → outlined → detailed → reviewed → baselined,
deprecated) for system and business use cases. Codes `REQ-`, `UC-`, `BUC-`, `BR-` are the
artifact key, assigned once.

Use case relations (include, extend, generalization) are trace link types limited to
use cases of the same kind, with cycle detection for include and generalization.

### Revisions and baselines

A baselined artifact keeps its identity and its code. Editing it creates a **pending
forward revision of the same entity**, which goes through the workflow again; the baselined
revision stays the effective (default) one until the new revision is baselined itself.
Material fields (title, statement, type; a use case's actor, trigger, conditions and flows)
cannot change in place in a baselined or deprecated revision. Revisions that took part in a
baseline are never deleted, so every baseline stays reproducible.

Traces point to the logical entity. A **Baseline** record (per Development, optionally
tied to a milestone) stores the exact revision IDs of every artifact and trace link that
was baselined at that moment; reaching a milestone takes one.

Work products follow the same rule (a revision of the same entity; superseded revisions
are locked, not only hidden in the UI).

### Traces

`trace_link` gains the UP rules: ends that belong to a Development must belong to the same
one; only a change request `impacts`; `supersedes` within one kind (existing `same_kind`).
Traceability is never inferred: no trace is created from a shared iteration, ticket or
name. Gap queries (approved requirements not refined downstream, accepted change requests
without impact analysis) run per Development.

### Access

Who sees a Development: its members (the equivalent of Odoo followers), its creator, its
Responsible and Process Managers (`administer unified process`). Everything that belongs to
a Development, including its artifacts and traces, is visible exactly when the Development
is, in entity access and in queries (lists, Views, counts). Process roles never grant
access. Process configuration is visible to every user of the Unified Process and edited by
Process Managers.

### Readiness and overview

`ReadinessCriterion` plugins (the 11 core criteria, more from integrations) evaluate the
Development's policy snapshot, optionally scoped to a milestone. Readiness explains what is
met and missing; it never blocks a transition and has no score. Overview cards are plugins
too.

### Odoo framework features

Followers → members field; chatter and field tracking → revision log messages;
`ir.sequence` → a locked counter service; record rules → access handlers plus query access;
archive flag → `status`; Many2oneReference → `trace_link`'s (entity type, id) pairs.

## Consequences

### Positive

- The process reuses Studio's artifact, trace, revision and moderation machinery instead of
  duplicating it per concept.
- Baselines are exact and reproducible; codes stay stable across revisions.
- Integrations plug in through readiness criteria, overview cards, trace link types and
  reference targets, without the core knowing them.

### Negative

- Query access for Development visibility has to cover artifacts and trace links as well as
  the process entities.
- Moving `requirement` and `use_case` from the generic artifact lifecycle to the UP
  lifecycles changes their states on existing sites (none in production yet).

### Neutral

- Fixes over the Odoo behaviour, deliberately: transitions are the only way to change a
  state; revisions are allowed only from locked states; superseded work products are locked
  server side; the same-Development rule also covers a work product's iteration and a
  milestone's phase; codes are unique.

## Related Decisions

- ADR-0002: Artifact and TraceLink as the engineering domain model.
- ADR-0004: Modeler platform (work products and traces pointing to diagrams).
- ADR-0006: Distribution as recipes.
