# Divengine Process

The Unified Process in Studio (ADR-0007), ported from the semantics of CodeIntel UP. It is
built in phases; this README describes what exists.

## Process configuration

Config entities, seeded with the standard process and edited by Process Managers under
*Divengine Studio › Unified Process*. The machine name is the stable code.

| Entity | Seed |
|---|---|
| `process_phase_type` | Inception, Elaboration, Construction, Transition, each with its closing milestone |
| `process_discipline` | 9 disciplines, from Business Modeling to Environment |
| `process_role` | 9 process roles and the disciplines they cover; playing a role grants no access |
| `work_product_definition` | 17 definitions (Vision, Software Architecture Description, Current Implementation Baseline…) |
| `activity_definition` | 15 definitions with discipline, responsible and participating roles, expected inputs and outputs |
| `development_case` | *Standard* (default, 24 lines) and *Existing System / Brownfield* (36 lines) |

A Development Case tailors the process line by line: a discipline, activity definition,
work product definition or readiness criterion is required, recommended, optional or
disabled, optionally for one milestone only. Every save that changes the lines increments
the case version by one; only one case is the default.

Configuration that other configuration still references cannot be deleted.

## Permissions

| Permission | Description |
|---|---|
| `use unified process` | Work on the Developments the user can see; read the process configuration. |
| `administer unified process` | Process Manager: edit the configuration, see every Development, delete process records. |

## Dependencies

- divengine_core

## Tests

`vendor/bin/phpunit -c phpunit.xml web/modules/custom/divengine_process`
