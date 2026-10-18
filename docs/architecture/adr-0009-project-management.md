# ADR-0009: Project management

## Status

Accepted

## Context

Teams using Studio also need to organise the work itself: projects, tasks, who does what
and where each piece stands. Many Drupal modules approach the topic; we build our own,
drawing on Odoo Project (stages, multiple assignees, subtasks), Azure DevOps (work item
types, hierarchy), Jira (project keys, issue links) and Trello (boards and cards), and on
Studio's own principles: explicit traces, Drupal-native building blocks, visibility by
membership.

## Decision

A module `divengine_project`, independent of the Unified Process (it depends on
`divengine_core`, `divengine_traceability` and core modules only).

| Concept | Implementation |
|---|---|
| Project | Content entity `project`: key (e.g. `BILL`), name, description, **parent project**, manager, members, status, dates, board stages. |
| Task | Revisionable content entity `project_task`: code from the project key (`BILL-0001`), type, stage, priority, assignees, tags, start date, deadline, estimate, **parent task**. |
| Board columns | Config entity `project_stage` with a category (not started, in progress, done, cancelled) and a "folded" flag; each project picks its stages. |
| Task types | Config entity `project_task_type` (Epic, Feature, Story, Task, Bug) with a colour. |
| Related tasks | **Traces**, with new types *relates to*, *blocks* and *duplicates* restricted to tasks (same kind). |
| Links to diagrams and artifacts | Traces; projects and tasks are trace ends. |
| Discussion | Core Comment module: a "Task comment" type and a comment field on tasks. |
| History | Drupal revisions of tasks; stage moves write a revision log ("Moved from To Do to Review"). |

Rules: parents form trees (no cycles); a subtask belongs to its parent's project; a task's
stage must be on its project's board; entering a Done/Cancelled stage records the closing
date. Visibility: a user sees the projects they own, manage or are a member of, and all their
subprojects; tasks follow their project (entity access and query alter). Project managers
(of the project or an ancestor) and users with *manage projects* edit projects; everyone in a
project works on its tasks.

The Kanban board uses core's Sortable library: dropping a card posts the new stage and the
column order (CSRF header token), and invalid moves are refused with the validation message.

## Consequences

- Related tasks need no extra tables and appear in the trace panel and in traceability
  reports like any other link.
- Integration with Developments (e.g. tasks for process activities) can come later without
  changing the model: a trace or a reference field.
