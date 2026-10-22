# ADR-0016: Who Owns Each Part of the MCP Settings

## Status

Accepted. Applies [ADR-0015](adr-0015-base-recipe-for-updates.md) to
`recipes/divengine_studio_mcp`.

## Context

Existing sites re-apply `divengine_studio_mcp` on every update (ADR-0015). The recipe set
`mcp.settings` with `simpleConfigUpdate`, which replaces whole top-level keys. Every update
therefore:

- turned token authentication off, because `auth_settings` was reset to the recipe's
  values and lost what `drush divengine:mcp-setup` had stored (#63);
- put back every plugin the recipe lists with the recipe's settings, undoing plugins an
  administrator had turned off or configured.

## Decision

`mcp.settings` has three owners:

| Part | Owner | On re-apply |
|---|---|---|
| `enable_auth` | The distribution | Always set to `true`. This is the security invariant: `/mcp/post` never answers without authentication. |
| `auth_settings` | `drush divengine:mcp-setup`, per installation | Not touched. The mcp module treats missing values as off, so a site without the setup step only lets in users with a session and the `use mcp server` permission. |
| `plugins` | The administrator, with the recipe's defaults | Only plugins the site does not have yet are added. |

The defaults use a `simpleConfigDefaults` config action, provided by `divengine_mcp`. It
sets each listed key only when the site has no value for it. Keys are dotted
(`plugins.content`), so a default covers one plugin entry and never merges into an entry
that already exists.

## Consequences

### Positive

- Updates keep token access and plugin choices.
- A plugin that a newer recipe adds reaches existing sites on their next update, without an
  update hook.

### Negative

- Changing the default settings of a plugin the site already has requires an update hook.
- A plugin entry removed from configuration (rather than disabled) comes back with its
  default on the next update.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Keep `simpleConfigUpdate` for `plugins` | Undoes administrators' changes on every update. |
| Set the defaults in `hook_install()` of `divengine_mcp` | Runs once; plugins added later would need an update hook each time, and the defaults would leave the recipe. |
| Stop re-applying the MCP recipe on updates | Role permissions and new plugins would no longer reach existing sites. |

## Related Decisions

- ADR-0006: Distribute Studio as recipes, with MCP as an optional layer.
- ADR-0015: A base recipe that existing sites re-apply on every update.
