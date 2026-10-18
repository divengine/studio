# Divengine Process

The Unified Process in Studio (ADR-0007), a port of the semantics of CodeIntel UP. One
Development is one governed software effort; everything below belongs to one Development
and is seen exactly by those who see it.

## What a Development has

| Tab | Content |
|---|---|
| Overview | Status bar with the lifecycle actions, the four phases as a stepper, readiness, next milestone, current iteration, team and process cards; panels for activities, missing work products, the business model, system use cases by state, change requests and traceability gaps; iterations, team, milestones, phases and the process snapshot. |
| Business model | Business use cases (BUC-), business rules (BR-), business actors and workers. |
| Requirements | Requirements (REQ-), system use cases (UC-) and system actors. |
| Process | Activities and work products (WP-). |
| Changes | Change requests (CR-). |
| Readiness | Every readiness criterion of the policy, overall and per milestone: met, missing and why. |
| Baselines | Snapshots (BL-) of the exact revisions in force; reaching a milestone takes one. |

**My work** lists the user's open activities, the iterations in progress and the change
requests to decide.

## Rules

- Lifecycles change only through transitions: Development, iteration, milestone, activity
  and change request in code (with guards: a block needs a reason, a decision a rationale,
  scheduling a target iteration, completing without the expected output a deviation);
  requirements, business rules, use cases and work products through content moderation.
  Baselined is the only published state and is never edited in place: editing creates a
  pending revision of the same record.
- Records and their references stay inside one Development; traces too, and only a change
  request impacts Unified Process records; includes and specializes never form a cycle.
- Readiness is advisory and scoreless; it never blocks a transition.
- A revision held by a baseline, or a record that has one, is never deleted. A Development
  with engineering records is not deleted either.
- Process roles never grant access; membership of the Development does.

## Process configuration

Config entities seeded with the standard process and edited by Process Managers under
*Divengine Studio › Unified Process*: 4 lifecycle phase types, 9 disciplines, 9 process
roles, 17 work product and 15 activity definitions, and the Development Cases *Standard*
(default) and *Existing System / Brownfield*. A case version increases by one on every save
that changes its lines; Developments keep the snapshot they applied until it is re-applied.

## Extension points

- Readiness criteria: plugins in `Plugin/ReadinessCriterion` with the `ReadinessCriterion`
  attribute.
- Trace end types: `hook_divengine_trace_end_types_alter()` (divengine_traceability).
- Trace link types and artifact types are configuration.

## Permissions

| Permission | Description |
|---|---|
| `use unified process` | Work on the Developments the user can see; read the process configuration. |
| `administer unified process` | Process Manager: edit the configuration, see every Development, delete process records. |

## Tests

`vendor/bin/phpunit -c phpunit.xml web/modules/custom/divengine_process`
