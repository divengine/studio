# Contributing to Divengine Studio

Thank you for your interest in Divengine Studio. Bug reports, reproductions, documentation
fixes, tests and code are welcome. This guide explains how to prepare a contribution that
can be reviewed.

Divengine Studio is maintainer-led (see [GOVERNANCE.md](GOVERNANCE.md)). The maintainer
decides the scope, architecture, roadmap, releases and merges. Opening an issue or a pull
request is a proposal: it is not approved work, and no contribution is accepted
automatically.

By taking part you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Before you start

- **Security vulnerabilities are never reported in public issues or pull requests.**
  Follow [SECURITY.md](SECURITY.md).
- **Search first.** Check the [open issues](https://github.com/divengine/studio/issues) and
  pull requests for the same topic.
- **Open an issue first for substantial changes**: new features, new modules or
  dependencies, changes to entities, storage, permissions, access rules, recipes, the
  installation profile, public contracts or architecture. Wait until the maintainer
  confirms the direction before you invest in an implementation. Small fixes (typos,
  broken links, a failing test with an obvious cause) can go straight to a pull request.
- **Picking an issue.** Issues labelled `good first issue` or `help wanted` are the best
  entry points. Comment on the issue before you start, so that work is not duplicated.
  An issue being open does not mean that any implementation of it will be merged.
- **Architecture decisions** are recorded in [docs/architecture](docs/architecture).
  A change that contradicts an accepted ADR needs a discussion in an issue, and usually
  a new or amended ADR, before code.

## Set up a development environment

[docs/development.md](docs/development.md) describes a reproducible local setup (PHP,
PostgreSQL, Composer, Drush), safe disposable test databases, all test types and
troubleshooting. In short:

```bash
git clone https://github.com/<your-account>/studio.git
cd studio
composer install
cp phpunit.xml.dist phpunit.xml   # set SIMPLETEST_DB to a disposable test database
```

## Workflow

1. Fork the repository and create a branch from the latest `main`, named after what it
   does, for example `fix/trace-panel-cache` or `docs/nginx-example`.
2. Keep the change focused on one issue. Unrelated refactoring, formatting or dependency
   updates go in separate pull requests.
3. Write commits in plain English, in the imperative mood ("Fix trace panel cache
   contexts"), each one a coherent step.
4. Run the checks that apply to your change (below) and note the results.
5. Open a pull request against `main` and fill in the template: linked issue, scope,
   user-visible changes, testing evidence, security, data and access impact,
   documentation and install or upgrade implications.
6. Keep the pull request up to date with `main` and answer review comments. The
   maintainer may ask for changes, split the work, or decline it.

Continuous integration runs on every pull request, including pull requests from forks,
with read-only permissions and no secrets. Workflows from first-time contributors may need
the maintainer's approval before they run.

## Coding standards

- Follow the [Drupal coding standards](https://www.drupal.org/docs/develop/standards)
  for PHP, YAML, Twig, CSS and JavaScript, and the patterns already used in the module
  you change.
- Use Drupal APIs: entities, Entity API storage and access, configuration entities,
  services, plugins, Form API, render arrays with cacheability metadata. Do not bypass
  access checks or write to entity tables with direct SQL.
- Declare `declare(strict_types=1);` in new PHP files and type parameters and return
  values.
- Configuration that Studio installs belongs to the modules' `config/install` or
  `config/optional`, or to `recipes/divengine_studio_base`. Changes to existing sites need
  an update hook or must be applied by re-applying the base recipe (ADR-0015).
- New database schema changes need an update hook (`hook_update_N()` or a post-update).
- User-facing strings are translatable (`$this->t()`, `{% trans %}`, `Drupal.t()`).
- Nothing is loaded from a CDN. Third-party JavaScript is vendored under the module's
  `js/vendor/` with its license and a `PROVENANCE.md` that records the source and version.

## Tests

Every behaviour change needs a test, and every bug fix a regression test that fails
without the fix.

| Change | Expected test |
|---|---|
| Entities, storage, access, services, configuration | Kernel test in `tests/src/Kernel` |
| HTTP, JSON:API, forms, installer screens | Functional test in `tests/src/Functional` |
| Editor and diagram JavaScript | Node.js test in `tests/js` |
| Recipes, installation profile, update path | `scripts/check-install-paths.sh`, plus an upgrade test described in the pull request |

Run the tests against a **dedicated, disposable** PostgreSQL database, never against a
database that holds data you want to keep. See
[docs/development.md](docs/development.md#tests) for the commands.

## Documentation

Update the documentation together with the code: the module's `README.md`, the main
`README.md` for installation and operation, the ADRs for architecture decisions, and
[CHANGELOG.md](CHANGELOG.md) under **Unreleased** for user-visible changes.

## Compatibility

- Studio targets Drupal 11 (currently 11.4), PHP 8.4 and PostgreSQL 16 or later. See
  [docs/releasing.md](docs/releasing.md#versioning-and-compatibility).
- Both installation paths must keep working: the `divengine_studio_installer` profile
  (web installer and `drush site:install`) and applying `recipes/divengine_studio_base`
  to an existing site.
- Existing sites are updated with `drush updatedb` and by re-applying the base recipe.
  Describe in the pull request what an existing site needs, and never rely on reinstalling.
- Do not edit stored history (revisions, baselines, captured values) or remove
  guarantees documented in the ADRs without an approved issue.

## Review

- The maintainer reviews pull requests when time allows; there is no guaranteed response
  time.
- Review looks at correctness, tests, security and access, data integrity, upgrade
  impact, documentation and fit with the architecture and roadmap.
- Approval and merge are decided by the maintainer. A pull request can be closed when it
  is out of scope, duplicates other work or stays inactive.
- Nothing is deployed from a pull request. Merging to `main` does not publish a release;
  releases follow [docs/releasing.md](docs/releasing.md).

## Licensing

Divengine Studio is licensed under the GNU General Public License, version 3 or later
([LICENSE](LICENSE)). By submitting a contribution you agree that it is distributed under
the same license. Only submit work that you have the right to contribute.

Third-party code must have a license compatible with GPL-3.0-or-later. Keep the original
license file and copyright notices of vendored code, and record its origin in
`PROVENANCE.md`.
