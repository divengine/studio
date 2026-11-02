# ADR-0021: Taking Part in a Development Is Not Managing It

## Status

Accepted (reconciled 2026-11-02). Proposed for #84 and merged in #88 after maintainer
review. Refines the access rules of [ADR-0007](adr-0007-unified-process.md).

### Approval is not management

Managing a Development, as its creator or Responsible, does **not** grant approval of its
records. Approval stays role-based:

- it needs the workflow's *Approve* transition permission, held by Content editor and
  Studio Manager;
- it needs the right to take part in the Development.

Independent approval, where an author cannot approve their own record, is not enforced.
Development-specific approval authority and independent approval are deferred to the beta
design backlog (#90). No approval behaviour changed with this ADR.

## Context

A Development was editable by everyone who saw it. One predicate, the Development's
visibility, decided `view` and `update` access, and creating a Development only needed
*Use the Unified Process*. So every member, including the least-privileged pilot account
of #72, could:

- start new Developments;
- edit a Development's details, members and Responsible;
- add or edit its phases, iterations, milestones, role assignments and baselines.

The same "update the Development" check also meant "may add records to it" in more than ten
places: the artifact, QA plan and external reference constraint, the MCP writers (#71, #86),
and the add buttons of QA, databases, sources and external work.

## Decision

Three powers, separated.

| Power | Who | Where it is checked |
|---|---|---|
| **Take part** (`contribute`) | Whoever sees the Development: members, creator, Responsible, Process Managers | `Development::access('contribute')` |
| **Manage** (`update`) | The creator, the Responsible and Process Managers | `Development::access('update')` |
| **Start** | *Create Developments* (new permission), or Process Manager | `development` create access |

**Taking part** means adding and editing the Development's records:

- artifacts, change requests and process activities;
- external references, QA plans, source repositories and database schemas;
- writes through MCP.

All of these now check `contribute`.

**Managing** means:

- the Development's form: details, members, Responsible, dates;
- the records of its process structure: phases, iterations, milestones, role assignments
  and baselines (`DevelopmentVisibility::STRUCTURE_TYPES`), including deleting role
  assignments.

`DevelopmentVisibility::operationFor()` gives the operation a record type needs. The scoped
access handler, the `ProcessDevelopmentEditable` constraint, now also on phases and
milestones, the scoped form and the Development pages all use it.

**Who created a Development** (`uid`) is changed only by Process Managers.

**Roles.**

- The Studio base recipe grants *Create Developments* to **Content editor**, the role that
  already started Developments, so its users keep doing so after an update.
- The QA roles and custom roles (`engineering_contributor`) do not get it.
- No update hook grants anything.

## Consequences

### Positive

- `engineering_contributor` and other members add engineering records and content to their
  Developments without being able to create Developments, change members or reshape the
  process.
- Every place that meant "may add records" now says so. Raising management rules later
  does not affect participation.

### Negative

- **Members lose management powers.** On existing sites, members who are not the creator or
  Responsible can no longer edit the Development, its members or its structure, or remove
  role assignments. Give such people the Responsible role, or *Administer the Unified
  Process*.
- **Some roles lose Development creation.** Users whose roles have *Use the Unified
  Process* but not Content editor (QA-only roles and custom roles) can no longer start
  Developments. Grant *Create Developments* to their roles if they should.
- **Process activities stay participation.** Members still create and update the
  activities they carry out; planning them is not separated.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Grant *Create Developments* in an update hook to every role with *Use the Unified Process* | It would hand the power back to every least-privileged role, `engineering_contributor` included. |
| A global *Manage Developments* permission | Management is about one Development; the creator and Responsible already express it per Development. Process Managers cover the global case. |
| Drop *Use the Unified Process* from the contributor role | Visibility needs it; the participant would not see their Development at all. |

## Related Decisions

- ADR-0007: Unified Process.
- ADR-0019: Engineering writes through MCP.
- ADR-0020: Administrator-configured content through MCP.
