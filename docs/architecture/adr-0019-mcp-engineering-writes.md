# ADR-0019: Engineering Writes Through MCP

## Status

Proposed for #71 (part of #68). Security-sensitive: the scope, roles and tool list below
need maintainer review before they are deployed.

## Context

Studio's MCP server was read-only (ADR-0006, ADR-0017). The maintainers want their AI
clients to populate Studio as engineering work happens: requirements, decisions, evidence,
the external work that implements and verifies them (ADR-0018), and the trace links
between them. The data stays governed by Studio: people approve, Developments decide who
sees what, every change keeps its history.

What already existed:

- OAuth 2.1 tokens that act as a person (ADR-0017). A scope with role granularity limits a
  token to what both its person and the scope's role may do; the read scope `mcp` uses the
  read-only `mcp_agent` role.
- Artifact access per type, moderation workflows, revisions and Development visibility;
  trace link endpoint access and `TraceRules` (#8, #33); `ExternalReferences::resolve()`
  with deduplication (#69).
- JSON:API, read-only on Studio sites.

## Decision

A new module, `divengine_mcp_write`, installed by `recipes/divengine_studio_mcp`, adds one
MCP plugin, `engineering`, with an enumerated set of tools:

| Tool | Does |
|---|---|
| `developments` | Lists the Developments the person sees, and whether they may add records. |
| `read` | Reads a requirement, decision, evidence or external reference by code, with its trace links. |
| `create_artifact` | Creates a requirement, decision or evidence in a Development. |
| `update_artifact` | Revises one: always a new revision, optionally only if it is still at `base_revision`. |
| `register_external` | Registers external work in a Development, or returns the existing reference. |
| `link` | Adds a typed trace link between two of those records. |
| `unlink` | Removes such a trace link, only with `confirm: true`. |

There is no generic entity write, no SQL and no JSON patch: each tool calls one method of
`EngineeringWriter`, which uses the Entity API.

**Who can write.** Four conditions, all checked on every call:

1. The request carries an OAuth token. The shared `mcp_bot` token and browser sessions
   never see these tools.
2. The token has the new **`mcp_write`** scope. Its role granularity is the new
   **MCP editor (scope)** role (`mcp_editor`), which holds only the permissions these tools
   need: create and edit requirements, decisions and evidence; their Draft, Proposed and
   Review transitions; create and delete trace links; link external work. The token can do
   what both its person and that role can do. The scope is checked on the token itself,
   because simple_oauth gives user 1 every permission whatever the scope.
3. The person has **Write engineering records through MCP**, given by the new **MCP
   writer** role. Nobody has it after installing or updating; an administrator grants it.
4. The person may do the operation in Studio: entity create and update access, update
   access to the Development, view access to both ends of a link, `TraceRules`.

**What clients cannot do.**

- Approve or baseline. The tools only set Draft, Proposed (requirements) or Review
  (decisions, evidence). A revision of a baselined requirement is a pending draft: the
  baselined revision stays the default until people approve and baseline the new one. A
  revision of an approved (not yet baselined) requirement sends it back to Draft, as
  editing it in Studio does, and it needs approval again.
- Write anything but requirements, decisions, evidence, external references and trace
  links between them.
- Change code, GitHub, configuration, users, roles, modules, deployments or baselines.

**Privacy.** A record or Development the person cannot see is answered exactly like a
missing one ("No record with code X is visible to you"), for reads and writes, so tools
are no existence oracle.

**Audit.**

- Every write is a revision by the person, with the log "Through MCP (client): reason".
- The `divengine_mcp_write` log channel records operation, entity, person and client.
  Content and tokens are never logged.

**Retries.**

- `create_artifact` accepts an `idempotency_key`, remembered for seven days per person
  together with the ID of the artifact it created, never its content. The same key with
  the same arguments returns that artifact as the person may see it now, after the same
  Development checks as a new request; if it was deleted or is hidden from them, the
  retry is refused without describing it and nothing is created. With different
  arguments the key is refused.
- `register_external` and `link` return the existing reference or link instead of a
  duplicate.
- `update_artifact` with `base_revision` refuses a stale revision.

**Codes for external references.** Tools address records by code. External references get
one: `EXT-0001`, assigned when registered. `divengine_external_update_11002` numbers
existing references without creating revisions, and `[[EXT-0001]]` links them in text.

**Scopes on registration.** Clients registered from now on may ask for both `mcp` and
`mcp_write`, and the discovery documents and the `WWW-Authenticate` challenge list both,
because clients such as Claude Code ask for the scopes the server advertises. A token
with `mcp_write` of a person without the MCP writer role cannot write. Clients registered
before keep only `mcp`.

## Consequences

### Positive

- Maintainers populate Studio from their connected clients, as themselves, with the same
  access, validation, revisions and approvals as in the Studio pages.
- Installing or updating grants nobody any write. Revoking the role, the token or the
  client stops writes at once.

### Negative

- People who connect a client from now on see the write scope on the consent page even if
  they may not use it.
- A token that may not use a tool gets the `mcp` module's generic JSON-RPC error
  (`Internal Error`), not a specific message. The refusal is logged as "Access denied to
  plugin: engineering".
- The idempotency of `create_artifact` is per key, not a lock: two different keys for the
  same content create two artifacts. Independent concurrent transactions are tested with
  #23.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Turn JSON:API writes on | Every entity type and field would become writable, with no Development-level rules beyond entity access and no audit of the client. |
| The `mcp` module's Tool API or Drush plugins | Generic: they would expose far more than engineering records. |
| Give the write permissions to the `mcp` scope | Every existing read-only token would become a write token. |
| A write service account | Writes would not be made as the person, and one leaked token could write to every Development. |
| Let clients approve | Approval is a human decision in Studio's governance. |

## Related Decisions

- ADR-0006: Distribute Studio as recipes, with MCP as an optional layer.
- ADR-0016: Who owns each part of the MCP settings.
- ADR-0017: OAuth 2.1 for MCP clients.
- ADR-0018: External references as Development records.
