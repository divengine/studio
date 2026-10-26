# Divengine MCP OAuth

Lets AI clients connect to Studio's MCP server with OAuth 2.1, as the person who
authorizes them (ADR-0017). Installed by the `divengine_studio_mcp` recipe, next to
`divengine_mcp`, whose token access keeps working.

## Responsibilities

- Discovery documents MCP clients read after a 401 from `/mcp/post`:
  - `/.well-known/oauth-protected-resource` (also under `/mcp/post`), RFC 9728;
  - `/.well-known/oauth-authorization-server`, RFC 8414.
- `POST /oauth/register`: dynamic client registration (RFC 7591). Clients get the `mcp`
  scope, PKCE and no automatic authorization; the consent page shows the client's name
  and the host its codes go to.
- `WWW-Authenticate: Bearer resource_metadata="…"` on 401 responses from `/mcp/post`.
- Tokens with the `mcp` scope are refused on every route other than `/mcp/post`.
- `drush divengine:mcp-oauth-setup`: writes the token signing keys to `private/oauth/`
  (`--key-dir` to change it, `--regenerate` to replace them) and points simple_oauth at
  them.
- A status report entry that warns while the keys are missing.

simple_oauth provides `/oauth/authorize` (login and consent) and `/oauth/token`.

## Access

A token can do what both its person and the `mcp_agent` role can do: it reads, and only
what the person may already read. People need the **MCP user** role to connect a client.

## Usage

```bash
drush divengine:mcp-oauth-setup --uri=https://studio.example.com
drush user:role:add mcp_user alice
```

Then, in the client, add the server by URL: `https://studio.example.com/mcp/post`.

- claude.ai and Claude Desktop: **Settings › Connectors › Add custom connector**.
- Claude Code: `claude mcp add --transport http divengine-studio https://studio.example.com/mcp/post`,
  then `/mcp` to log in.
- Cursor, VS Code and other clients: an HTTP MCP server with that URL and no headers.

The browser opens Studio's login and consent page; after **Allow**, the client gets its
tokens. Tokens can be revoked in **Configuration › People › Simple OAuth** and clients in
**Configuration › Web services › Consumers**.

## Dependencies

- divengine_mcp
- mcp
- consumers
- simple_oauth
