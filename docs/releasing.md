# Releases, versioning and compatibility

How Divengine Studio is versioned, what each release supports and how a release is
prepared. Releases are made only when the maintainer approves them (see
[GOVERNANCE.md](../GOVERNANCE.md)); nothing in this document publishes a release by
itself.

## Current status

**No release has been published.** `main` is **alpha** by the criteria below: the
installation paths are verified, but features and stored data are still changing, the
upgrade path is tested only between selected commits and open integrity work remains
(see the known limitations in [CHANGELOG.md](../CHANGELOG.md)).

## Release stages

A stage is claimed only when its evidence exists for the release commit.

| Stage | Evidence required | What users can expect |
|---|---|---|
| **alpha** | Required CI jobs pass. `scripts/check-install-paths.sh` passes on all paths. Installer Functional tests pass. Known limitations are listed. | For evaluation. Data, configuration and APIs may change; upgrading from an earlier alpha is attempted but may need manual steps. |
| **beta** | Alpha evidence, plus: the scope of the release is complete; an upgrade test from the previous pre-release passes; no known open bug causes data loss, an access bypass or a failed installation; deprecations from Studio's own code are addressed. | Upgrades from the previous beta are supported through the documented update steps. APIs and data structures change only when needed to fix bugs. |
| **release candidate** | Beta evidence on the candidate commit, with no change other than fixes since. | The release unless a blocking problem is found. |
| **stable** | Release candidate evidence, plus: an upgrade test from the previous stable release (or the last beta for the first stable); private vulnerability reporting enabled; a documented support window for the release line. | Supported as described in [SECURITY.md](../SECURITY.md) and below. No promise of response or fix times. |

## Versioning and compatibility

### Version numbers

Studio uses [Semantic Versioning](https://semver.org/) for the distribution as a whole
(installation profile, recipes, modules and theme together):

- **MAJOR**: changes that need more than the documented update steps (for example a new
  Drupal core major version, removed features or data that cannot be migrated
  automatically).
- **MINOR**: new features and new configuration, applied to existing sites with the
  documented update steps.
- **PATCH**: bug fixes and security fixes, with no new configuration beyond what the fix
  needs.
- Pre-releases carry a suffix: `1.0.0-alpha1`, `1.0.0-beta1`, `1.0.0-rc1`.

Tags are the bare version (`1.0.0-alpha1`, without a `v` prefix), the format Composer and
Drupal.org both accept. The numbering of the first release is a maintainer decision.

### Supported platform

Each release states in its notes the versions it was tested with. For the current `main`:

| Component | Policy | Current |
|---|---|---|
| Drupal core | One Drupal 11 minor per release line, the one locked in `composer.lock`. Moving to a new core minor is a MINOR or PATCH release (PATCH when it is a security update). Drupal 12 will be a new MAJOR. | 11.4.10 |
| PHP | The tested version is supported; the minimum is Drupal core's. | 8.4 tested; 8.3 accepted by core, not tested |
| PostgreSQL | The minimum Drupal core accepts, with `pg_trgm`. Other databases are not supported. | 16 or later |
| Drush, Composer | The versions locked in `composer.lock`; Composer 2. | Drush 13.8.0 |
| Installation profile | `divengine_studio_installer` is the way to install new sites, through the web installer or `drush site:install`. | |
| Recipes | `recipes/divengine_studio` is for new sites only. `recipes/divengine_studio_base` is applied to existing Drupal 11 sites that have what the Standard recipe or profile provides, and re-applied on every update (ADR-0015). | |
| MCP integration | Optional. `recipes/divengine_studio_mcp` and the `drupal/mcp` and `drupal/jsonapi_schema` versions in `composer.lock`. Read-only. Sites without it are fully supported. | drupal/mcp 1.2.3 |

### Upgrade support boundaries

Supported, once beta releases exist:

- Updating from the previous release of the same MAJOR line with the documented steps:

  ```bash
  composer install
  vendor/bin/drush updatedb --yes
  vendor/bin/drush recipe $PWD/recipes/divengine_studio_base
  vendor/bin/drush recipe $PWD/recipes/divengine_studio_mcp   # if MCP is installed
  vendor/bin/drush cache:rebuild
  ```

- Sites installed with the installation profile, and Standard-based sites with
  `divengine_studio_base` applied.

Not supported:

- Downgrades, or skipping a MAJOR version.
- Re-applying `recipes/divengine_studio` (with Drupal's Standard recipe) to an existing
  site.
- Sites installed with the Minimal profile, or databases other than PostgreSQL.
- Keeping local changes to configuration that the base recipe sets again on every update:
  the front page, the default and administration themes and the editor buttons and
  filters of Basic and Full HTML.
- Code or data changed outside Drupal's APIs (for example direct SQL on Studio tables).

During alpha, upgrades between commits of `main` are tested for selected changes only
and may need manual steps, which are described in the pull request and the changelog.

## Release checklist

Run every step on the exact commit to be released. Record the results in the release
issue; a failed step stops the release.

1. **Approval.** The maintainer opens or confirms a release issue with the target
   version and stage.
2. **Commit.** Choose the commit on `main` and record its SHA. Only fixes for problems
   found by this checklist are merged until the tag is created.
3. **Dependencies.**
   - `composer validate --strict`
   - `composer audit --locked`; when a source is unreachable, check the
     [Drupal security advisories](https://www.drupal.org/security) for every contributed
     project by hand.
   - `composer outdated --locked --direct` and decide on pending security or bug-fix
     updates of Drupal core and contributed modules.
4. **CI.** All required jobs of `.github/workflows/ci.yml` pass on the commit: `static`,
   `phpunit (kernel)`, `phpunit (functional)`, `install paths`, `javascript`, `codedocs`.
   Known deprecation notices are compared with the previous release.
5. **Clean installation.** On a disposable PostgreSQL server, with the tested PHP version:

   ```bash
   STUDIO_INSTALLCHECK_SERVER=pgsql://user:password@127.0.0.1:5432 scripts/check-install-paths.sh
   ```

   All three paths pass and `drush core:requirements` reports no errors. Then install
   once through `/core/install.php` in a browser, with and without the MCP integration,
   and check the Studio home, **My work** and the administration theme.
6. **Applying the recipe to a supported site.** The `recipe-on-standard` path of step 5
   covers a new Standard site. Also apply `recipes/divengine_studio_base` to a copy of a
   Standard site installed with the previous Drupal minor, if the release changes the
   core minor.
7. **Upgrade test.** First run the scripted path from the previous release (for the first
   release, the previous tested commit):

   ```bash
   STUDIO_UPGRADE_FROM=<previous tag or commit> STUDIO_INSTALLCHECK_SERVER=... scripts/check-install-paths.sh upgrade
   ```

   It covers configuration and schema updates of an empty site. Then, by hand, in a
   throw-away site and database: install the previous release, add representative content (a
   Development with an iteration and a baseline, artifacts, trace links, a diagram, a QA
   run, a project task), switch to the release commit and run the update steps above.
   Then:

   ```bash
   vendor/bin/drush updatedb:status            # nothing pending
   vendor/bin/drush core:requirements --severity=2
   vendor/bin/drush php:script scripts/install-check.php -- <0|1 for MCP> "<site name>"
   ```

   and check that the content is still there and accessible to the right people.
8. **Security review.** Review the changes since the previous release for routes,
   permissions, access checks and queries, file handling, user input and output
   escaping, and configuration that grants permissions. Confirm that no reported
   vulnerability is unresolved without a decision.
9. **License notices.** `LICENSE` is present. Every vendored library under
   `web/modules/custom/*/js/vendor/` has its license file and a `PROVENANCE.md` matching
   the shipped version. `composer licenses --no-dev` lists no license incompatible with
   GPL-3.0-or-later.
10. **Documentation.** README requirements and installation and update steps match what
    was tested. The **Unreleased** section of `CHANGELOG.md` becomes the release section,
    with upgrade notes and known limitations.
11. **Artifacts.** The release is the tagged source. Drupal core, contributed modules and
    `vendor/` are not included; users run `composer install`. No archive containing
    `settings.php`, credentials, logs or private files is attached.
12. **Tag.** After the maintainer's approval of the results:

    ```bash
    git tag -a 1.0.0-alpha1 -m "Divengine Studio 1.0.0-alpha1" <sha>
    git push origin 1.0.0-alpha1
    ```

13. **GitHub Release.** Create it from the tag with the changelog section as notes, and
    mark alpha, beta and release candidates as pre-releases:

    ```bash
    gh release create 1.0.0-alpha1 --verify-tag --prerelease --title "1.0.0-alpha1" --notes-file notes.md
    ```

14. **After the release.** Start a new **Unreleased** section and close the release issue
    with links to the tag, the release and the recorded evidence.

## Drupal.org distribution readiness

Studio is developed and distributed through GitHub. Publishing it as a Drupal.org
distribution is **not planned now**; this section records what it would take, so that
the decision can be made later.

### Prerequisites

- **License.** Drupal.org hosts code under GPL-2.0-or-later only. Studio is licensed under
  GPL-3.0-or-later, so it would have to be relicensed with the agreement of every
  copyright holder, or stay off Drupal.org.
- **Third-party code.** Drupal.org repositories generally do not accept bundled
  third-party libraries. Studio vendors its diagram, code browser and schema explorer
  engines under `js/vendor/` so that nothing is loaded from a CDN. They would have to be
  installed as Composer or asset-packagist libraries, or covered by an exception.
- **Project structure.** The profile, the recipes and the modules would need Drupal.org
  projects with free machine names, and a decision whether the modules are separate
  projects or part of the profile project. The repository's root `composer.json`
  describes a whole site (type `project`), not a profile, recipe or module package.
- **Releases.** Drupal.org security advisory coverage requires stable releases and an
  opt-in review; alpha and beta releases are not covered.
- **Process.** Issue queue, merge requests and release management would move to
  Drupal.org, or be mirrored.

### Trade-offs

| Benefit | Cost |
|---|---|
| Discoverability for Drupal site builders | Relicensing, or not being eligible at all |
| Drupal.org packaging and update status | Replacing vendored libraries and the offline guarantee |
| Security team coverage for stable releases | Two places for issues and contributions, or a migration |
| Integration with Drupal's project browser and recipe tooling | More release overhead per project |

### Recommendation

Keep GitHub as the home of the project. Re-assess after the first beta, once the scope
and the vendored libraries are stable. No migration, relicensing or publication is
started by this document.
