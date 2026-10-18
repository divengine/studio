# ADR-0008: Test management in Studio

## Status

Accepted

## Context

Studio needs manual test management: test plans with suites, cases and steps; runs
that freeze a snapshot of the Ready cases; step and execution results with corrections,
evidence and QA Issues; a dashboard and printable reports. Tests must also verify
Unified Process records through explicit traces.

The functional model defines the records, state machines, write rules, workflow
actions, dashboard and roles. Its implementation uses Drupal entities and Studio's
existing process, traceability and access services.

## Decision

### Module

`divengine_qa` depends on `divengine_process`, `divengine_artifact` and
`divengine_traceability`. Every QA record belongs to the **Development** of its plan
and follows its visibility (`hook_divengine_process_scoped_types_alter()`). Verification
of Unified Process records lives in the same module because the process dependency
already exists.

### Records

| Concept | Studio representation |
|---|---|
| Test plan, suite, case and step | content entities `qa_test_plan` (TP-), `qa_test_suite` (TS-), `qa_test_case` (TC-), `qa_test_step` |
| Test run, execution and step result | `qa_test_run` (RUN-), `qa_test_execution`, `qa_step_result` |
| result correction | `qa_result_correction`, immutable (save and delete refused) |
| environment, stage, priority, tag | config entities, seeded |
| QA Issue | content entity `qa_issue` (QI-): open, in progress, resolved, cancelled |
| Activity tracking | `qa_log` activity entries; tracked fields logged on update |
| attachments as evidence | private file fields, append-only rules in the services |
| Guarded workflow writes | services check the workflow rules, then save as *trusted*; entity constraints refuse the same changes through any other path (forms, REST, JSON:API) |

Plans and cases use the Unified Process lifecycle engine (transitions with guards).
Runs change state only through `QaRunWorkflow` (start, automatic completion on the last
result, complete, cancel, reset, reopen with audit, reassign). Results go through
`QaResultRecorder` (derived execution result, skip case, bulk block/skip, corrections with
a reason). Deletion of historical evidence is refused (`QaDeletion`), both in entity
access and in `preDelete()`.

### Roles

Four cumulative permissions, `use qa` < `execute qa tests` < `manage qa` <
`administer qa`, granted by the recipe to the roles QA User, QA Tester, QA Lead and QA
Manager. Testers see only the runs assigned to them (entity access and query alter).

### Verification of process records

Tests (plan, suite, case, run) are related to process records only through **Verifies**
traces whose target is a requirement, use case, change request or work product. The
verification status is derived on read from the QA results; readiness criteria
`requirements_verified`, `use_cases_verified` and `change_requests_evidenced` explain each
gap and are recommended by the Standard and Existing System cases.

### Presentation and reports

- Reports use printable pages (print or save as PDF from the browser) and show
  labels instead of raw keys.
- The dashboard draws its doughnuts with CSS.
- The QA Issue description uses a dedicated text format with tables; there is no global
  "Bug" tag: a `qa_issue` is always a QA Issue.

## Consequences

- Evidence requires the private file system (`$settings['file_private_path']`); the
  status report flags it.
- Everything QA is in one module and one recipe entry, including verification of
  process records.
