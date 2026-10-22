# Divengine MCP

Connects Divengine Studio to AI clients (Claude Code, Claude Desktop, Cursor) through the
contributed [MCP](https://www.drupal.org/project/mcp) module. Installed by the
`divengine_studio_mcp` recipe (ADR-0006); no other Divengine module depends on it.

## Responsibilities

- `drush divengine:mcp-setup`: the per-installation step a recipe cannot do, because it
  involves a secret.
  - Creates the service account (`--user`, default `mcp_bot`) with the `mcp_agent` role.
  - Writes a random 256-bit token to `--token-file` (default
    `private/divengine-mcp.token` next to the document root, mode 0600) unless one exists;
    `--regenerate` replaces it.
  - Points the Key entity `divengine_mcp_token` at that file and turns on token auth in
    `mcp.settings`.
  - Prints the endpoint and the `claude mcp add` command. The token itself is never
    printed or stored in configuration.

- `simpleConfigDefaults` config action: sets simple configuration values only where the
  site has none. The MCP recipe gives its plugin defaults this way, so re-applying it on
  updates keeps what administrators changed (ADR-0016).

The token file must be readable by the user PHP runs as.

Re-applying the recipe always turns `enable_auth` back on and never touches
`auth_settings`, which belongs to `divengine:mcp-setup`.

## Usage

```bash
drush divengine:mcp-setup --uri=https://studio.example.com
claude mcp add --transport http divengine-studio https://studio.example.com/mcp/post \
  --header "Authorization: Basic $(base64 -w0 < private/divengine-mcp.token)"
```

Clients send `Authorization: Basic base64(token)`.

## Dependencies

- divengine_core
- key
- mcp

## Related Modules

- divengine_artifact, divengine_modeler, divengine_traceability (read through JSON:API)
- divengine_codedocs exposes `codedocs_sets`, `codedocs_snapshots`, `codedocs_units`,
  `codedocs_symbols`, `codedocs_symbol`, `codedocs_endpoints` and `codedocs_diff`.
  The MCP recipe and setup grant the agent `use code docs`; add the account to the
  Developments whose documentation it should read. See the Code Docs module README
  and `scripts/codedocs-demo/README.md` for reproducible PHP and TypeScript examples.
