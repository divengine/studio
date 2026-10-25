# ADR-0017: OAuth 2.1 for MCP Clients

## Status

Accepted. Extends [ADR-0006](adr-0006-distribution-as-recipes.md) (MCP as an optional
layer) and [ADR-0016](adr-0016-mcp-settings-ownership.md).

## Context

Studio's MCP server accepted one credential: a token for the `mcp_bot` service account,
sent as `Authorization: Basic base64(token)`. That works for clients that let you set a
header (Claude Code, Cursor, VS Code), but:

- web chat clients (claude.ai custom connectors, ChatGPT) only connect to remote MCP
  servers through OAuth, and cannot send custom headers;
- every client sees what `mcp_bot` sees, not what the person using it may see;
- the token never expires and is shared by every client that has it.

The MCP authorization specification asks the server to:

- answer 401 with `WWW-Authenticate: Bearer resource_metadata="…"`;
- publish OAuth 2.0 Protected Resource Metadata (RFC 9728);
- publish OAuth 2.0 Authorization Server Metadata (RFC 8414);
- support the authorization code grant with PKCE (S256);
- support OAuth 2.0 Dynamic Client Registration (RFC 7591), since clients do not know
  the server in advance.

`drupal/simple_oauth` 6.1 provides the authorization server (authorization code, PKCE,
refresh tokens, consent page, scopes intersected with the user's permissions), and the
`mcp` module's `/mcp/post` route already accepts its `oauth2` authentication provider.
Its own `mcp_auth` provider only applies to `Basic` headers, so bearer tokens reach
simple_oauth without changes to contributed code. No contributed module provides the
discovery documents or dynamic registration.

## Decision

A new module, `divengine_mcp_oauth`, installed by `recipes/divengine_studio_mcp`, adds what
simple_oauth lacks:

| Part | Where |
|---|---|
| `/.well-known/oauth-protected-resource` and `…/mcp/post` | `OAuthMetadataController` |
| `/.well-known/oauth-authorization-server` | `OAuthMetadataController` |
| `POST /oauth/register` | `ClientRegistrationController` |
| `WWW-Authenticate` challenge on 401 from `/mcp/post` | `McpOAuthSubscriber` |
| Tokens with the `mcp` scope refused outside `/mcp/post` | `McpOAuthSubscriber` |
| Token signing keys | `drush divengine:mcp-oauth-setup` |

**Access.** Tokens act as the person who authorized the client. The `mcp` scope has role
granularity on `mcp_agent`, and simple_oauth's access policy intersects the scope's
permissions with the person's own, so a token can only read, and only what both its
person and the `mcp_agent` role can read. People need the new **MCP user** role
(`use mcp server`, `grant simple_oauth codes`) to connect a client.

**Audience.** simple_oauth authenticates bearer tokens on every route. A token with the
`mcp` scope is only accepted on `/mcp/post`, so a client cannot use it against JSON:API or
other routes. User 1 keeps the super user pass in simple_oauth's access policy; this
restriction is what bounds such a token.

**Registration.** Registration is open, as MCP clients expect. A registered client gets
nothing on its own: a person must log in and press **Allow**. To make phishing harder:

- the consent page names the client together with the host its codes are sent to
  (`Claude (claude.ai)`);
- redirect URIs must be `https`, `http` on a loopback host, or a native application scheme
  (`cursor://`), and may not have a fragment;
- every client must use PKCE, gets only the `mcp` scope, the `authorization_code` and
  `refresh_token` grants, and never automatic authorization;
- registrations are limited to 20 per hour per IP address.

Access tokens last one hour and refresh tokens 30 days. Public clients (`none`) get no
secret; clients that ask for `client_secret_post` or `client_secret_basic` get one, stored
hashed.

**Secrets.** As with the MCP token (ADR-0006), keys are not created by the recipe:
`drush divengine:mcp-oauth-setup` writes them to `private/oauth/` and points
`simple_oauth.settings` at them. The status report warns while they are missing.

**Token access stays.** `Basic` token access for `mcp_bot` keeps working for clients and
scripts that use it.

## Consequences

### Positive

- claude.ai, Claude Desktop, ChatGPT and other OAuth-only clients can connect by URL.
- Each person's client sees what that person may see, and access can be revoked per
  token (**Configuration › People › Simple OAuth**) or per client (**Configuration › Web
  services › Consumers**).
- Clients that support headers can keep the token, or switch to OAuth.

### Negative

- Two more contributed modules (`simple_oauth`, `consumers`) and their libraries.
- Registered clients accumulate; there is no clean-up of clients that were never
  authorized yet.
- The web server must serve `/.well-known/oauth-*` through Drupal (the README's examples
  already exclude `/.well-known/` from the hidden-file rule).

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| An external authorization server (Keycloak, Auth0) | Another service to run and keep in sync with Studio's users and permissions. |
| A proxy in front of `/mcp/post` that turns OAuth into the `mcp_bot` token | Every person would act as `mcp_bot`; the proxy would need its own login. |
| Patch the `mcp` module | simple_oauth already authenticates bearer tokens on `/mcp/post`; only discovery and registration were missing. |
| Pre-registered clients only | claude.ai and other clients register themselves and offer no way to enter a client ID for every server. |

## Related Decisions

- ADR-0006: Distribute Studio as recipes, with MCP as an optional layer.
- ADR-0015: A base recipe that existing sites re-apply on every update.
- ADR-0016: Who owns each part of the MCP settings.
