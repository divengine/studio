# ADR-0006: Distribute Studio as Recipes, with MCP as an Optional Layer

## Status

Accepted

## Context

ADR-0001 left open whether Studio would ship as a recipe or an install profile. The
repository had become a site project: a `composer.json` of type `project` with the custom
modules inside, installed by hand (`site:install` followed by `drush en`). Adding the MCP
server to that `composer.json` blurred the line between the product and one environment:
every install got MCP, although none of the Divengine modules depend on it.

We want two things at once:
- A complete, ready-to-use solution, MCP included, from a single install.
- Modules that stay independent of MCP and of each other's packaging, so they can be
  installed into any Drupal 11 site and, later, published on drupal.org.

Install profiles are the legacy way to ship a distribution; since Drupal 10.3 the core
installer and Drupal CMS itself are built on recipes.

## Decision

Package Studio in three layers, all in this repository for now:

1. **Modules** (`web/modules/custom/divengine_*`). None of them depends on MCP.
   `divengine_mcp` is the only one that does, and only the MCP recipe installs it.
2. **Recipes** (`recipes/`):
   - `divengine_studio` (type Site): builds on `core/recipes/standard`, installs the
     modules and Gin, imports their config entities, adds the "Diagram" button and the
     "Embed diagrams" filter to Basic and Full HTML, and grants the Content editor role
     day-to-day Studio permissions.
   - `divengine_studio_mcp` (type Integration): installs `mcp`, `key`, `jsonapi`,
     `jsonapi_schema` and `divengine_mcp`, enables the General, Content and JSON:API
     plugins, turns authentication on and creates the read-only `mcp_agent` role.
3. **Project** (this `composer.json`): requires everything both recipes need. Installing
   the full solution is `site:install recipes/divengine_studio` followed by applying
   `recipes/divengine_studio_mcp`.

Secrets never go into a recipe. `drush divengine:mcp-setup` creates the service account,
writes a random token to `private/divengine-mcp.token` (outside the document root,
ignored by git), points a Key entity at it and turns on token auth.

## Consequences

### Positive

- One documented path installs the whole suite, reproducibly, from an empty database.
- MCP can be left out by skipping one recipe and two Composer packages.
- Modules remain portable to any Drupal 11 site.
- Recipes can be re-applied to existing sites to pick up new configuration.

### Negative

- Recipes do not install config entities shipped in a module's `config/install`; each
  module that ships them must be listed under `config.import` in the recipe.
- Recipes cannot require Composer packages while they live inside the project; the root
  `composer.json` has to carry them until the recipes are split into their own packages.

### Neutral

- The MCP service exposed by `drupal/mcp` 1.2 is read-only here: JSON:API is read-only and
  the service account only holds view permissions. Writes keep going through Drush.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Install profile | Legacy mechanism; cannot be applied to an existing site; core and Drupal CMS have moved to recipes. |
| MCP as a dependency of the Divengine modules | Couples the product to one integration that installers may not want. |
| Separate repositories now | Premature; the split can follow once the recipes and modules stabilize. |

## Related Decisions

- ADR-0001: Use Drupal core as the base.
