# ADR-0015: A Base Recipe That Existing Sites Re-apply on Every Update

## Status

Accepted. Refines [ADR-0006](adr-0006-distribution-as-recipes.md) and
[ADR-0014](adr-0014-installation-profile-entry-point.md): the recipes remain the single
definition of Studio, now split so that existing sites can be updated with them.

## Context

ADR-0006 notes that recipes can be re-applied to existing sites to pick up new
configuration. Studio sites are updated that way: deploy the code, `drush updatedb`, then
re-apply the recipe with `strict: false` so that configuration the site already has is
kept.

`recipes/divengine_studio` built on `core/recipes/standard`. Updating Drupal core from
11.3 to 11.4 showed two problems with that:

- **Core's recipes change between releases and are compared strictly.** In 11.4 the
  `basic_block_type` recipe defines the basic block body as `text_long` instead of
  `text_with_summary` and treats that field storage strictly. Drupal updates neither the
  field nor the recipe's expectation, so re-applying `core/recipes/standard` 11.4 on any
  site created with 11.3 fails before Studio's own configuration is touched. Studio's
  `strict: false` only applies to Studio's recipe, not to its sub-recipes.
- **Studio relied on parts of Standard it never declared.** The 11.4 Standard recipe no
  longer includes the Article and Basic page content types, Tags, comments on articles,
  search, shortcuts or the toolbar. Studio's knowledge articles used the `body` field
  storage and Node's view modes that those content types used to bring, so a new
  installation failed.

## Decision

1. **`recipes/divengine_studio_base`** contains everything Studio adds: its modules, Gin,
   the imported configuration, the editor buttons and filters and the role permissions.
   It has no sub-recipes and expects a site with what the Standard recipe or profile
   provides (the Basic and Full HTML formats with their editors, the Content editor role).
2. **`recipes/divengine_studio`** is `core/recipes/standard` followed by
   `divengine_studio_base`. The installation profile and `drush site:install` keep applying
   it to new sites, so new sites follow the Standard recipe of the Drupal release they are
   installed with.
3. **Existing sites are updated with `divengine_studio_base`** (and
   `divengine_studio_mcp` when MCP is installed) after `drush updatedb`. Core's own
   configuration on those sites is updated by core's update hooks, not by re-applying
   core's recipes.
4. **Studio declares what it uses.** The base recipe ships the `node.body` field storage in
   its `config/` directory and imports Node's view modes and bulk actions, as Core's
   content type recipes do.

## Consequences

### Positive

- Updating an existing site does not depend on how Core's recipes evolve; a Drupal minor
  update cannot block Studio's update step.
- New sites still get the current Standard setup and Studio on top of it.
- Studio installs no longer depend on example content types Studio does not use.

### Negative

- New and existing sites can differ in what Core's Standard recipe provides. For example,
  sites installed with 11.4 use the Navigation module and have no Article or Basic page
  content types, while sites installed earlier keep the toolbar and those content types.
- `divengine_studio_base` cannot be applied to a site without the Standard text formats
  and roles (for example a Minimal installation).

### Neutral

- The `recipe-on-standard` path of `scripts/check-install-paths.sh` applies the base
  recipe, as an existing site would.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Keep Standard as a sub-recipe and list the content types it dropped | Re-applying would still fail on the strict block body storage of every site created before 11.4. |
| Repair the block body field storage in an update hook | Changing a field type with data is unsafe, and the next Core recipe change would block updates again. |
| Stop re-applying recipes and ship configuration through update hooks only | Duplicates the recipe as code; new configuration would be defined twice. |

## Related Decisions

- ADR-0006: Distribute Studio as recipes, with MCP as an optional layer.
- ADR-0014: A branded installation profile as the entry point, recipes as the definition.
