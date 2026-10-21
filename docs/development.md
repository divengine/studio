# Development

How to set up Divengine Studio locally, run every kind of test safely and diagnose
common problems. For contribution rules see [CONTRIBUTING.md](../CONTRIBUTING.md); for
installing a site for real use see the [README](../README.md#installation).

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4 | Tested version. Drupal 11.4 accepts 8.3, which is not tested for Studio. Extensions: `pdo_pgsql`, `gd`, `mbstring`, `xml`, `dom`, `curl`, `zip`, `intl`, `opcache`; `pdo_sqlite` for the CodeDocs integration test. |
| PostgreSQL | 16 or later | With the `pg_trgm` extension (part of PostgreSQL's contrib package). |
| Composer | 2 | |
| Drush | 13.8 | Installed by Composer as a development dependency (`vendor/bin/drush`). |
| Node.js | current LTS | Only for the JavaScript tests. |
| Git | any recent | Used by Code Source. |

Drupal core, contributed modules and `vendor/` are not in the repository. `.gitignore` is
an allowlist: new top-level files or directories must be added to it explicitly.

## Get the code

```bash
git clone https://github.com/<your-account>/studio.git
cd studio
composer install
```

`composer install` also scaffolds Drupal's files under `web/` (for example
`web/index.php`, `web/.ht.router.php`, `web/sites/default/default.settings.php`).

## A local site

Use a database for your local site and a **different** database for tests.

```bash
# As a PostgreSQL superuser, once:
createuser --pwprompt studio
createdb -O studio studio
psql -d studio -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
```

Create `web/sites/default/settings.php` from `default.settings.php` and add at least:

```php
$settings['file_private_path'] = '../private/files';
$settings['trusted_host_patterns'] = ['^127\.0\.0\.1$', '^localhost$'];
```

Install with the installation profile, without a browser:

```bash
vendor/bin/drush site:install divengine_studio_installer \
  --db-url=pgsql://studio:password@127.0.0.1/studio --site-name='Divengine Studio' \
  --account-name=admin --account-mail=admin@example.com
# add divengine_studio_installer_integrations_form.mcp=1 to include the MCP integration
```

or serve the site and open `/core/install.php` in a browser:

```bash
cd web && php -S 127.0.0.1:8888 .ht.router.php
```

PHP's built-in server is for local development only. For production use a real web
server configured as described in the [README](../README.md#web-server-security).

After pulling new code, update your site the way existing sites are updated:

```bash
composer install
vendor/bin/drush updatedb --yes
vendor/bin/drush recipe $PWD/recipes/divengine_studio_base
vendor/bin/drush recipe $PWD/recipes/divengine_studio_mcp   # if MCP is installed
vendor/bin/drush cache:rebuild
```

## Test databases

Tests create and drop tables, and some scripts drop whole schemas or databases. Use a
dedicated PostgreSQL role and database that hold nothing you want to keep, on a server
that holds no production data.

```bash
# As a PostgreSQL superuser, once:
createuser --createdb --pwprompt studio_test
createdb -O studio_test studio_test
psql -d studio_test -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
```

`CREATEDB` lets `scripts/check-install-paths.sh` create its own databases. Then:

```bash
cp phpunit.xml.dist phpunit.xml
```

and set `SIMPLETEST_DB` in `phpunit.xml` to
`pgsql://studio_test:password@127.0.0.1/studio_test`. `phpunit.xml` is ignored by Git;
never commit credentials. The `<env>` values in `phpunit.xml` do not override variables
already set in your environment.

## Tests

| Type | Location | Needs |
|---|---|---|
| Kernel | `web/modules/custom/*/tests/src/Kernel` | Test database |
| Functional | `web/modules/custom/*/tests/src/Functional`, `web/profiles/custom/*/tests/src/Functional` | Test database and a web server |
| Installation paths | `scripts/check-install-paths.sh` | A PostgreSQL role allowed to create databases |
| JavaScript | `web/modules/custom/*/tests/js` | Node.js and `composer install` (one test uses core's CKEditor 5 build) |
| CodeDocs integration | `web/modules/custom/divengine_codedocs/tests/run.php` | `pdo_sqlite`; uses an in-memory SQLite database |

### Kernel tests

```bash
vendor/bin/phpunit -c phpunit.xml --testsuite kernel
vendor/bin/phpunit -c phpunit.xml --filter BaselineCaptureTest
vendor/bin/phpunit -c phpunit.xml web/modules/custom/divengine_artifact
```

Each test installs what it needs into tables with a random prefix in `SIMPLETEST_DB` and
removes them afterwards.

### Functional tests

Functional tests drive a real Drupal site through HTTP: `TraceAccessHttpTest`
(JSON:API and cached collections) and the installer profile tests, which run the
interactive web installer with and without the MCP integration. They need a web server
serving `web/` of this checkout at `SIMPLETEST_BASE_URL`:

```bash
(cd web && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8888 .ht.router.php) &
SIMPLETEST_BASE_URL=http://127.0.0.1:8888 vendor/bin/phpunit -c phpunit.xml --testsuite functional
```

The installer tests install all of Studio and take several minutes each.

### All PHPUnit tests

```bash
scripts/test.sh                       # everything under web/modules/custom
scripts/test.sh --filter TraceLinkTest
```

`scripts/test.sh` needs `phpunit.xml`. It includes `TraceAccessHttpTest`, so start a web
server and set `SIMPLETEST_BASE_URL` first, or that test fails.

### Installation paths

`scripts/check-install-paths.sh` verifies the supported ways of installing Studio, each
in a new database named `studio_installcheck_*` that is created for the run and dropped
afterwards, and a throw-away site directory under `web/sites/`:

- `profile`: the installation profile, MCP not selected;
- `profile-mcp`: the installation profile with the MCP integration;
- `recipe-on-standard`: a Standard site with `recipes/divengine_studio_base` applied.

```bash
STUDIO_INSTALLCHECK_SERVER=pgsql://studio_test:password@127.0.0.1:5432 scripts/check-install-paths.sh
STUDIO_INSTALLCHECK_SERVER=... scripts/check-install-paths.sh profile   # one path
# when the role cannot create databases:
STUDIO_INSTALLCHECK_PG_ADMIN="sudo -n -u postgres" STUDIO_INSTALLCHECK_SERVER=... scripts/check-install-paths.sh
```

It never resets or reuses an existing database. Logs are written to
`private/installcheck-<path>.log`.

### Scripts that empty the test database

`scripts/check-install.sh` and `scripts/check.sh` (which runs `scripts/test.sh` and then
`check-install.sh`) **drop and recreate the `public` schema of the database in
`SIMPLETEST_DB`** (or `STUDIO_TEST_DB`). Run them only when that database is dedicated
to tests and disposable. Prefer `scripts/check-install-paths.sh`, which covers the same
installation with new databases. CI does not run these two scripts.

### JavaScript tests

The editor and diagram JavaScript is tested with Node.js's test runner and jsdom, against
the vendored engines and the CKEditor 5 build of core:

```bash
for m in divengine_modeler divengine_modeler_bpmn divengine_modeler_uml divengine_diagram_embed; do
  (cd web/modules/custom/$m/tests/js && npm ci && npm test)
done
```

Each directory has its own `package.json` and `package-lock.json`; `node_modules/` is
ignored by Git.

### CodeDocs integration test

```bash
php web/modules/custom/divengine_codedocs/tests/run.php
```

It runs the CodeDocs index against Drupal's real database API on an in-memory SQLite
database and never touches a site. It needs the `pdo_sqlite` extension.

### Static checks

```bash
composer validate --strict
composer audit --locked --ignore-unreachable
find web/modules/custom web/themes/custom web/profiles/custom scripts -type f \
  \( -name '*.php' -o -name '*.module' -o -name '*.install' -o -name '*.inc' -o -name '*.theme' -o -name '*.profile' \) \
  -not -path '*/js/vendor/*' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 -P4 php -l
find recipes web/modules/custom web/themes/custom web/profiles/custom -type f -name '*.yml' \
  -not -path '*/js/vendor/*' -not -path '*/node_modules/*' -print0 | xargs -0 vendor/bin/yaml-lint --parse-tags
```

`--ignore-unreachable` is needed while `packages.drupal.org/8` answers 404 for the
security advisories endpoint that its metadata advertises (observed in October 2026).
While it does, `composer audit` checks Packagist's advisory database, which covers Drupal
core but not contributed projects such as Gin or MCP: check the
[Drupal security advisories](https://www.drupal.org/security) for those. CI shows a
warning when a source is unreachable.

## Continuous integration

`.github/workflows/ci.yml` runs on every pull request (forks included) and on pushes to
`main`, with read-only permissions and no secrets. Each job uses a fresh runner and, where
needed, a throw-away PostgreSQL 16 service.

| Job | What it runs |
|---|---|
| `static` | `composer validate`, `composer audit`, `php -l`, YAML lint |
| `phpunit (kernel)` | `vendor/bin/phpunit --testsuite kernel` |
| `phpunit (functional)` | `vendor/bin/phpunit --testsuite functional` with PHP's built-in web server |
| `install paths` | `scripts/check-install-paths.sh` (all three paths) |
| `javascript` | `npm ci && npm test` in each `tests/js` package |
| `codedocs` | `php web/modules/custom/divengine_codedocs/tests/run.php` |

## Diagnostics

```bash
vendor/bin/drush status                          # Drupal, PHP, database and paths
vendor/bin/drush core:requirements --severity=2  # requirement errors
vendor/bin/drush updatedb:status                 # pending database updates
vendor/bin/drush watchdog:show --count=50        # recent log messages
vendor/bin/drush php:script scripts/install-check.php -- 0 "Site name"   # health checks
vendor/bin/drush php:script scripts/diagnose-baselines.php              # read-only baseline check
```

`scripts/install-check.php` prints one line per check and `FAILED_CHECKS=<n>`; the first
argument is `1` when MCP is expected. `scripts/diagnose-baselines.php` runs in a
read-only PostgreSQL transaction and prints identifiers and counts only.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Installation fails mentioning `pg_trgm` | Create the extension in the database: `CREATE EXTENSION IF NOT EXISTS pg_trgm`. On some systems install PostgreSQL's contrib package first. |
| Kernel tests fail to connect | Check `SIMPLETEST_DB` in `phpunit.xml`, or an environment variable with the same name that takes precedence. |
| Functional tests fail at the first request | No web server at `SIMPLETEST_BASE_URL`, or it serves another checkout. Start `php -S` from this checkout's `web/`. |
| Functional tests cannot write files | `web/sites/simpletest` must be writable by the user running PHPUnit and the web server. |
| `check-install-paths.sh` cannot create databases | Grant `CREATEDB` to the role, or set `STUDIO_INSTALLCHECK_PG_ADMIN`. |
| `drush recipe` is unknown | Drush older than 13.8 with Drupal 11.4. Run `composer install` to get the locked version. |
| `drush recipe` changes the wrong site in a multisite | Set `DRUPAL_DEV_SITE_PATH=sites/<site>`; Drupal's recipe command otherwise uses `sites/default`. |
| Re-applying `recipes/divengine_studio` fails on an existing site | Expected: update existing sites with `recipes/divengine_studio_base` (ADR-0015). |
| Private files or the MCP token are missing | Set `$settings['file_private_path']` before installing. |
| Deprecation notices in test output | Known notices from contributed modules and some Studio code; they do not fail the run. New notices from your change should be fixed. |
| `composer audit` fails with an unreachable repository | Use `--ignore-unreachable` and check contributed projects by hand (see [Static checks](#static-checks)). |
