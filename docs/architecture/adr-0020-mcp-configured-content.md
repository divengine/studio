# ADR-0020: Administrator-Configured Content Through MCP

## Status

Accepted (reconciled 2026-11-02). Proposed for #86 and merged in #87 after maintainer and
architecture review. Builds on [ADR-0019](adr-0019-mcp-engineering-writes.md). No content
capability is configured by installing or updating. Generic content writes stay separate
from workflow approval: records are created unpublished, and publication and moderation
fields cannot be written.

## Context

Engineering writes (ADR-0019) are enumerated tools for requirements, decisions, evidence and
external references. Studio also needs MCP clients to read and write ordinary content, first
Knowledge Articles in Spanish for the Áncora pilot, and other bundles later, without a new
PHP plugin per model.

The audit on #86 found no configuration-only option:

- **`drupal/mcp` 1.2.3** reads JSON:API but never writes. Its per-plugin and per-tool roles
  see the token's scope roles, and administrators bypass them.
- **Tool API and Tool Belt** (beta/alpha) offer generic entity tools, but:
  - they have no allowlist per bundle, operation or field;
  - configured inputs are only defaults that clients override;
  - Knowledge Articles would be published on create;
  - `kb_development` could be reassigned.
- **`drupal/mcp` 2.x** replaces the whole stack and has no such allowlist either.
- **JSON:API writes** are a site-wide switch.

## Decision

`divengine_mcp_write` gets a configuration entity, two MCP plugins and a service.

**Capabilities.** A `mcp_content_capability` config entity per bundle, ID
`<entity type>--<bundle>`, edited at **Configuration › Web services › MCP content**
(permission *Administer MCP content capabilities*). It holds:

- the operations read, create, update and delete, each off by default;
- the writable fields;
- the Development field, and whether creation requires a Development.

Recipes ship none and never overwrite them, so they survive re-application and updates.

**Supported models fail closed** (`ContentModels`), checked when saved and again at every
call.

- **Entity types:** only content entity types that are fieldable, have a UUID, are
  revisionable with a log, have an owner and a published flag, and are not moderated
  (states change through workflow transitions, which generic tools do not perform).
  People, files, OAuth records, comments, path aliases and Studio records with their own
  workflows (artifacts, trace links, external references, Developments and process
  records) are excluded by name. Today that leaves node bundles.
- **Writable fields:** only these types: plain and formatted text, booleans, numbers,
  lists, dates, and references to content.
  - Entity keys and revision fields are never writable, nor `created`, `changed`,
    `promote`, `sticky`, `path` or the moderation state.
  - Development references are never writable fields.
  - Fields of other types (files, images, comments, links…) are refused.

**Tools.**

| Plugin | Tools | Who |
|---|---|---|
| `records` | `records_capabilities`, `records_list`, `records_get` | Any MCP connection with *Use MCP server*, read as its person; no write scope or role needed. |
| `records-write` | `records-write_create`, `records-write_update`, `records-write_delete` | The gates of ADR-0019: a personal OAuth token with `mcp_write`, of a person whose stored account has the MCP writer role (#79). |

Each tool appears only when some capability enables its operation, and its `type` enum holds
only those bundles. Turning an operation off removes it from the next `tools/list`. The
catalogue stays at six tools whatever the number of bundles.

**Every call** passes both:

- the capability: operation enabled, fields allowlisted, model still supported;
- Drupal, as the person: create, view, update and delete access, field edit access, entity
  validation, text formats the person may use, reference targets the person may view, and
  the right to take part in the Development (`contribute`, ADR-0021).

**Writes never change what Studio governs.**

- **New records:** unpublished, owned by the person.
- **Never set by clients:** ownership, publication, revision fields and moderation state.
- **Set on creation only, from their own arguments:** the Development (`development`, a
  code) and the language (`langcode`).
- **Revisions:** every update is a new revision with
  `Through MCP (client): <log>` and the person as revision user.
- **Rollback:** the save runs in a transaction. If the model's own hooks change the
  Development (for example a Knowledge Article taking its parent's Development) or the
  publication, the save is refused and rolled back.

**Reads.**

- **Private drafts:** a person reads and revises their own unpublished drafts through
  Drupal's view and update access. Lists add the person's own unpublished records, which
  node grants leave out, and check each record with entity view access.
- **Hidden fields and records:** fields without view access are left out. References to
  records the person cannot see are omitted. Hidden records and Developments answer exactly
  like missing ones.

**Retries and limits.**

- `base_revision` refuses stale updates.
- Idempotency keys work as in ADR-0019: the ID only, rechecked on replay.
- Lists are capped at 50 records.

**Delete** is off by default. When enabled it needs `confirm: true` and the person's own
delete access, and runs the entity's delete with its hooks; nothing cascades from MCP.

**Permissions** are not granted by code. A write token can do what both its person and the
`mcp_editor` scope role can do. So an administrator who enables writes for a bundle also
gives `mcp_editor` the bundle's permissions (for nodes: create, edit own, and
*view own unpublished content*). The capability form says whether the scope role can create
records of the bundle.

## Consequences

### Positive

- Another supported bundle is enabled by configuration only, with the same guarantees.
- Engineering writes, read tools and the OAuth model are unchanged.

### Negative

- Moderated bundles, files and media, and multilingual translations of an existing record
  are not supported.
- Enabling writes for a bundle is two administrator steps: the capability, and the scope
  role's permissions.
- Lists check access record by record after the query, so a page can hold fewer records than
  the limit.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Tool API + Tool Belt | No bundle, operation or field allowlist; configured inputs are only defaults; pre-release dependencies. |
| `drupal/mcp` 2.x | Replaces the MCP stack instead of configuring it; pre-release; no allowlist. |
| JSON:API writes, with or without JSON:API Extras | A site-wide switch; no per-operation policy or MCP writer gates. |
| One generated tool per bundle | An unbounded tool catalogue that changes its shape with configuration. |
| A Knowledge-only tool | Every new model would need code (#86 asks for none). |

## Related Decisions

- ADR-0016: Who owns each part of the MCP settings.
- ADR-0017: OAuth 2.1 for MCP clients.
- ADR-0019: Engineering writes through MCP.
