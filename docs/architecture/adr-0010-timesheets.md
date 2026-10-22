# ADR-0010: Timesheets

## Status

Accepted

## Context

Project management (ADR-0009) needs time tracking like Odoo's Timesheets: people log the
time they spend on tasks, tasks compare it with their allocated time, and managers see who
spent how much where.

## Decision

A module `divengine_timesheet` on top of `divengine_project`, as Odoo's `hr_timesheet` sits on
`project`.

| Concept | Implementation |
|---|---|
| Timesheet line | Content entity `timesheet`: person, date, project, optional task, description, hours (decimal). The project of an entry on a task is the task's project: an entry with a task and no project takes it, and a contradicting project is refused on validation and on save (#10). |
| Allocated time | The task's existing `estimate` field, labelled *Allocated time*. |
| Task figures | Spent (own entries), on subtasks (all descendants), total, remaining (negative = overtime), progress. Totals count every entry, as everyone who sees a task sees how long it took. |
| Timer | One per person, kept in `user.data`; stopping rounds up to the quarter hour and prefills *Log time*. Starting another stops the running one and logs it. |
| Weekly grid | *My timesheets*: rows = tasks with time this week, open tasks assigned to the user and added lines; cells accept `1.5` or `1:30`. A changed cell creates, updates or deletes its only entry, or writes an **adjustment** entry (allowed to be negative) when it holds several, like Odoo. |
| Report | Period, project, person and grouping (person, project, task). |

Access: people see and change their own entries on projects they see; managers of a project
(or an ancestor) and people with *manage timesheets* see and correct everyone's entries on
their projects. Lists follow the same rule through a query alter.

## Consequences

- Timesheet validation and approval (Odoo Enterprise) are not included; they can be added
  as a state on entries later.
- Billing and costs (rates per person) stay out of scope.
