# Divengine MCP engineering writes

Lets the AI client of an authorized person create and revise requirements, decisions and
evidence, register external work and link them, through an enumerated set of MCP tools
(ADR-0019). Installed by the `divengine_studio_mcp` recipe; nobody can write until an
administrator grants the **MCP writer** role.

## Who can write

All of these, on every call:

- The client is connected with OAuth (ADR-0017), not with the shared `mcp_bot` token.
- Its token has the `mcp_write` scope. The token can do what both its person and the
  **MCP editor (scope)** role can do.
- The person's account explicitly has the **MCP writer** role, and the **MCP user** role.
  Having every permission is not enough: user 1 and administrators need the role too
  (#79). Removing the role, deleting it or blocking the account stops writes on the next
  request.
- The person may do the same thing in Studio: create or edit that artifact type, add
  records to the Development, see both ends of a link. Content editors can.

Clients connected before this module was installed only have the `mcp` scope: remove the
connection and connect again to get write tools.

## Tools

The tools appear as `engineering_*` in the client.

| Tool | Does |
|---|---|
| `developments` | The Developments you see, and whether you can add records. Start here. |
| `read` | A record by code (`REQ-0001`, `DEC-0001`, `EVD-0001`, `EXT-0001`), its latest revision, state and trace links. |
| `create_artifact` | A requirement, decision or evidence, as a draft or submitted. Requirements need a `statement`. Pass an `idempotency_key` to retry safely. |
| `update_artifact` | A new revision. Pass `base_revision` from `read` to refuse if it changed meanwhile; `log` is required. |
| `register_external` | Registers a GitHub issue, pull request, commit, Actions run, repository or web page, or returns the existing reference. |
| `link` | A typed trace link, read "source type target": `EXT-0003 implements REQ-0001`, `EXT-0004 verifies REQ-0001`. |
| `unlink` | Removes a trace link; needs `confirm: true` after asking the person. |

A typical session:

1. `developments`
2. `create_artifact` (the requirement)
3. `register_external` (the pull request)
4. `link` (the pull request `implements` the requirement)
5. `read` (check the result)

Clients never approve: they set Draft, Proposed (requirements) or Review (decisions,
evidence). People approve and baseline in Studio.

## Configured content (ADR-0020)

Besides the engineering tools, administrators can let clients work with ordinary content
bundles, for example Knowledge Articles, without new code. At **Configuration › Web services
› MCP content** (*Administer MCP content capabilities*), add a content type and choose:

- the operations: read, create, update, and delete (delete off unless needed);
- the writable fields (fields that decide identity, ownership, publication, revisions or the
  Development, and unsupported field types, are never offered);
- the Development field and whether creating a record needs a Development.

| Tool | Who | Does |
|---|---|---|
| `records_capabilities` | any MCP connection | The enabled types, operations, writable fields and Development rules. |
| `records_list`, `records_get` | any MCP connection, as its person | Records the person may see, including their own unpublished drafts. |
| `records-write_create` | MCP writers (as above) | A record, unpublished and owned by the person, in a Development and language given by code. |
| `records-write_update` | MCP writers | A new revision; publication, owner, Development and language never change. |
| `records-write_delete` | MCP writers, only if enabled | Deletes with `confirm: true` and the person's own delete access. |

A write token can only do what both its person and the `mcp_editor` scope role may do. To
let clients create, for example, Knowledge Articles, also give `mcp_editor` the bundle's
permissions (`create knowledge_article content`, `edit own knowledge_article content`,
`view own unpublished content`). The capability form says whether that is still missing;
nothing grants it automatically.

Only bundles of content entity types that are revisionable with a log, owned, publishable
and not moderated can be configured; Studio's own records keep their dedicated tools.

## Audit

- Every write is a revision by the person, with the log `Through MCP (<client>): <reason>`.
- The `divengine_mcp_write` log channel records operation, entity, person and client,
  never the content or the token.
- Revoke access by removing the MCP writer role, the token
  (**Configuration › People › Simple OAuth**) or the client (**Consumers**).

## Dependencies

- divengine_mcp, divengine_mcp_oauth (simple_oauth, mcp)
- divengine_artifact, divengine_traceability, divengine_process, divengine_external
