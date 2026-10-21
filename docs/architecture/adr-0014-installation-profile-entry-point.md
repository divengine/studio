# ADR-0014: A Branded Installation Profile as the Entry Point, Recipes as the Definition

## Status

Accepted. Partially supersedes [ADR-0006](adr-0006-distribution-as-recipes.md): it
replaces the installation path and the rejection of an install profile; the module,
recipe and MCP layering of ADR-0006 stays.

## Context

ADR-0006 made recipes the only way to install Studio:
`drush site:install recipes/divengine_studio`, or `/core/install.php?recipe=…` in a
browser. Studio is a product installed as it is and customized afterwards. Its operators
expect to deploy the code, create a database and open `/core/install.php` like any
distribution, and see Studio's name, its optional integrations and progress on the way.

Drupal 11.3 offers this only to installation profiles:

- A profile that declares `distribution` is selected automatically by `/core/install.php`
  (`_install_select_profile()`), names the installer screens, sets the installer theme
  and the page opened at the end (`distribution.install.finish_url`).
- `hook_install_tasks()` and `hook_install_tasks_alter()` add and order interactive and
  batch installer steps. A recipe has no way to add a screen.
- `RecipeRunner::toBatchOperations()` turns a recipe into batch operations. Core's own
  `install_recipe_batch()` uses it for `?recipe=` installations.

Clean installations through the recipe also showed missing configuration (issue #25). Its
causes were in the recipe and a module, not in the absence of a profile:

- Recipes install extensions with configuration syncing enabled, so modules' and themes'
  configuration entities, including optional configuration, are not created unless the
  recipe imports them. Gin's blocks, `views.view.files` and `views.view.moderated_content`
  were not imported.
- `divengine_project` never shipped the Project task form display; Drupal built it at
  runtime.
- `node.type.knowledge_article` declared `help: ''`, which Drupal's configuration
  validation rejects. Validation is skipped inside the installer and runs when a recipe is
  applied to an existing site, so only that path failed.

## Decision

1. **The installation profile `divengine_studio_installer`** in
   `web/profiles/custom/` is the documented, primary way to install Studio. It declares
   the *Divengine Studio* distribution, the `divengine_studio_installer_theme` installer
   theme and `/studio` as the finish page.
2. **The profile adds no modules or configuration.** Its info file installs nothing. Its
   installer tasks apply `recipes/divengine_studio` with `RecipeRunner::toBatchOperations()`,
   and `recipes/divengine_studio_mcp` afterwards when the operator selects the
   **MCP integration** step, which is unchecked by default. The recipes remain the single
   definition of Studio's modules and configuration.
3. **Recipes run before the site configuration form**, as in Core's recipe installer, so
   the site name, e-mail and administrator account entered there are saved last. The
   profile only suggests *Divengine Studio* as the site name. The recipe no longer sets
   the site name: applying it to an existing site keeps that site's name.
4. **No secrets during installation.** `drush divengine:mcp-setup` is not run by the
   installer; the first page after installing tells the operator to run it.
5. **The installer theme is self-contained.** It is a hidden theme inside the profile,
   based on Claro (Core), with Divengine's palette and logo copied from the Studio theme.
   No Studio module or theme exists while it is used, and nothing comes from a CDN.
6. **Applying the recipe to an existing Drupal 11 site stays supported** as the secondary
   path. Both paths are verified by `scripts/check-install-paths.sh` and by the profile's
   Functional tests.
7. The configuration defects are fixed declaratively: the recipe imports the optional
   configuration Studio relies on, `divengine_project` ships its form display, and the
   node type uses `help: null`. No repair step runs at the end of the installation.

## Consequences

### Positive

- Installing Studio is the usual Drupal experience: deploy, create a database, open
  `/core/install.php`.
- One definition of the product (the recipes) serves the web installer, Drush and
  existing sites.
- Clean installations through either path have no pending entity definition updates and
  contain the expected configuration.

### Negative

- New sites record `divengine_studio_installer` as their installation profile. Drupal
  core cannot uninstall or replace a site's profile, so the profile must stay in the
  codebase.
- If the installer batch fails, Drupal shows the batch error. Like Core's recipe
  installer, there is no automatic resume: the operator fixes the cause and installs again
  into an empty database.
- The installer locates the recipes at `../recipes` relative to the web root, as this
  project's layout has them.

### Neutral

- Sites installed before this decision keep their recorded profile (`false` for recipe
  installations, or `standard`) and need no migration. Re-applying the recipe imports the
  configuration they lack, except configuration that already exists.
- `drush site:install recipes/divengine_studio` keeps working, but it is no longer the
  documented path.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| Keep `/core/install.php?recipe=` as the web path | No distribution name or theme, no integration step, and an operator must know the query parameter. |
| A profile that lists the modules and ships the configuration | Duplicates the recipe; the two definitions would drift. |
| A final installer task that repairs missing configuration or entity definitions | Hides installation-order defects instead of fixing them, and would not help existing sites that apply the recipe. |

## Related Decisions

- ADR-0006: Distribute Studio as recipes, with MCP as an optional layer.
