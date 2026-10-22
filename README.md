# Divengine Studio

Drupal 11 distribution with Divengine's custom modules for managing artifacts,
traceability, knowledge and engineering diagrams. It installs from its own web installer;
the recipes define what is installed and how sites are updated (ADR-0006, ADR-0014, ADR-0015).

This repository only tracks Divengine's own code. Drupal core, contributed modules and
`vendor/` are installed with Composer.

## Contents

| Path | Purpose |
|---|---|
| `web/modules/custom/divengine_core` | Shared services, interfaces, constants and permissions |
| `web/modules/custom/divengine_artifact` | Artifact entities and artifact types |
| `web/modules/custom/divengine_knowledge` | Knowledge configuration |
| `web/modules/custom/divengine_traceability` | Trace links between artifacts, diagrams and content |
| `web/modules/custom/divengine_modeler` | Diagram platform: diagram entity, notation plugins, safe previews, element links |
| `web/modules/custom/divengine_modeler_bpmn` | BPMN 2.0 notation (bpmn-js) |
| `web/modules/custom/divengine_modeler_uml` | UML notation (PlantUML in the browser, CodeMirror) |
| `web/modules/custom/divengine_diagram_embed` | Diagrams in formatted text: CKEditor 5 button, `<drupal-diagram>` filter, usage |
| `web/modules/custom/divengine_process` | Unified Process: Developments with phases, iterations and milestones, business and system model, activities, work products, change requests, traces, readiness, baselines, My work |
| `web/modules/custom/divengine_qa` | Test management: plans, suites, cases, steps, runs with frozen snapshots, results, corrections, evidence, QA Issues, dashboard, reports; verification of process records (ADR-0008) |
| `web/modules/custom/divengine_project` | Project management: projects and subprojects, tasks and subtasks on Kanban boards, related tasks, comments, links to diagrams and artifacts (ADR-0009) |
| `web/modules/custom/divengine_timesheet` | Time tracking: timesheet entries, allocated vs spent time on tasks, timer, weekly grid, reports (ADR-0010) |
| `web/modules/custom/divengine_thread` | Discussion with attachments on every record, shown as a side panel (ADR-0011) |
| `web/modules/custom/divengine_articles` | Knowledge base: hierarchical articles per Development, embedded diagrams, `[[CODE]]` links (ADR-0011) |
| `web/modules/custom/divengine_source` | Code Source: Git repositories of a Development indexed from the object database, code browser, MCP tools for agents (ADR-0012) |
| `web/modules/custom/divengine_database` | Database schemas: snapshots from any analyzer through a normalized contract (SchemaSpy XML included), schema explorer, schema evolution, findings, MCP tools (ADR-0013) |
| `web/modules/custom/divengine_codedocs` | Code documentation for any language: common contract, Javadoc/TypeScript adapters, chunked publishing API, symbols, references, coverage and snapshot comparisons. [Usage](web/modules/custom/divengine_codedocs/README.md) |
| `web/themes/custom/divengine_studio_theme` | The Studio workspace theme (default theme; Gin stays for configuration) |
| `web/modules/custom/divengine_mcp` | Optional: service account and token for the MCP server (`drush divengine:mcp-setup`) |
| `web/profiles/custom/divengine_studio_installer` | Installation profile: the branded web installer, which applies the recipes, and its installer theme |
| `recipes/divengine_studio` | Site recipe for new sites: Drupal's Standard recipe + `divengine_studio_base` |
| `recipes/divengine_studio_base` | All Studio modules, Gin, diagram embedding in Basic/Full HTML, editor permissions; re-applied to update existing sites |
| `recipes/divengine_studio_mcp` | Optional recipe: read-only MCP server (drupal/mcp + JSON:API) with token auth |
| `docs/` | Architecture decisions, roadmap, development and release guides, MVP scope, research and handoff notes |
| `scripts/codedocs-demo/` | Runnable PHP and TypeScript shop examples, extraction/import scripts, MCP reads and screenshot capture |
| `composer.json`, `composer.lock` | Exact Drupal core and contrib versions |
| `phpunit.xml.dist`, `bootstrap.kernel.php` | Test configuration |
| `.github/` | Issue forms, pull request template, CODEOWNERS and CI |

## Direction

Studio is becoming an engineering suite: explicit artifacts and traces, diagram modelers
(BPMN, UML, Mermaid, DMN, CMMN, IFML, ArchiMate, Excalidraw) whose elements link to Drupal entities, and a
Unified Process layer. See [docs/roadmap.md](docs/roadmap.md) and the ADRs in
[docs/architecture](docs/architecture).

## Installation

Divengine Studio is a complete distribution: install it as it is, then customize it.

### Requirements

| Requirement | Notes |
|---|---|
| PHP 8.4 | Studio is tested with PHP 8.4. Drupal 11.4 itself requires at least PHP 8.3 and recommends 8.4; PHP 8.3 is not tested for Studio. |
| PostgreSQL 16 or later | The minimum Drupal 11.4 accepts. The `pg_trgm` extension is required by Drupal's PostgreSQL driver. |
| `git` | Used by Code Source to index repositories. |
| Private file system | Test evidence, attachments and the MCP token are private files. Set `$settings['file_private_path']` to a directory outside the web root **before installing**. |
| Composer 2 | To install Drupal core, contributed modules and `vendor/`. |

### Install with the web installer (recommended)

1. Deploy the code and its dependencies:

   ```bash
   composer install
   ```

2. Create an empty PostgreSQL database and enable `pg_trgm` in it:

   ```bash
   createdb -O studio studio
   psql -d studio -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
   ```

3. Prepare `web/sites/default/settings.php` from `default.settings.php` and set at least:

   ```php
   $settings['file_private_path'] = '../private/files';
   $settings['trusted_host_patterns'] = ['^studio\.example\.com$'];
   ```

4. Configure the web server (see [Web server security](#web-server-security)) and open
   `https://studio.example.com/core/install.php`. No query parameter is needed: the
   **Divengine Studio** distribution is selected automatically.

The installer shows these steps:

| Step | What happens |
|---|---|
| Choose language | Drupal's language selection, with the Divengine Studio branding. |
| Verify requirements | Only shown when the server lacks something. |
| Set up database | Enter the PostgreSQL database created above. |
| Install site | Drupal's base system and the installation profile. |
| Choose integrations | Optional integrations. **MCP integration** is unchecked by default. |
| Install Divengine Studio | Applies `recipes/divengine_studio` with a progress bar. |
| Install MCP integration | Only when selected: applies `recipes/divengine_studio_mcp`. |
| Configure site | Site name (suggested: *Divengine Studio*), site e-mail and the administrator account. Nothing is pre-filled with real addresses or credentials. |

When it finishes, the browser opens `/studio`, the Studio home, which takes the
administrator to **My work**. The default theme is the Studio theme and Gin is the
administration theme.

If you selected the MCP integration, create its service account and token afterwards on
the server, as the installer reminds you:

```bash
vendor/bin/drush divengine:mcp-setup --uri=https://studio.example.com
```

The same installation can run without a browser:

```bash
vendor/bin/drush site:install divengine_studio_installer \
  --db-url=pgsql://studio:password@localhost/studio --site-name='Divengine Studio' \
  --account-name=admin --account-mail=you@example.com
# add divengine_studio_installer_integrations_form.mcp=1 to include the MCP integration
```

### Apply Studio to an existing Drupal 11 site

`recipes/divengine_studio_base` is the definition of what Studio installs. It can be
applied to an existing Drupal 11 site that has what the Standard recipe or profile provides
(the Basic and Full HTML formats with their editors and the Content editor role):

```bash
vendor/bin/drush recipe $PWD/recipes/divengine_studio_base
vendor/bin/drush recipe $PWD/recipes/divengine_studio_mcp   # optional
```

`recipes/divengine_studio` adds Drupal's Standard recipe in front of it and is what the
installer applies to new sites. Do not re-apply it to an existing site: the Standard
recipe's configuration changes between Drupal releases (in 11.4, for example, the type of
the basic block body field) and Drupal refuses to apply it over the earlier version.

### Update an existing site

Deploy the new code, then apply database updates and re-apply the base recipe, which
imports the configuration that new features bring and keeps what the site already has:

```bash
composer install
vendor/bin/drush updatedb --yes
vendor/bin/drush recipe $PWD/recipes/divengine_studio_base
vendor/bin/drush recipe $PWD/recipes/divengine_studio_mcp   # if MCP is installed
vendor/bin/drush cache:rebuild
```

Re-applying the recipe also sets again the values it defines for existing configuration:
the front page, the default and administration themes and the editor buttons and filters
of Basic and Full HTML. The MCP recipe turns MCP authentication back on, keeps the token
access set up by `drush divengine:mcp-setup` and the MCP plugin settings, and only adds
plugins the site does not have yet ([ADR-0016](docs/architecture/adr-0016-mcp-settings-ownership.md)).

Applying the recipe keeps the site's name and sets `/studio` as the front page, the
Studio theme as default and Gin as administration theme. In a multisite installation,
Drupal's recipe command uses `sites/default` unless `DRUPAL_DEV_SITE_PATH` names the
site directory (for example `DRUPAL_DEV_SITE_PATH=sites/studio`).
See [ADR-0014](docs/architecture/adr-0014-installation-profile-entry-point.md) for how the
installer and the recipes relate, and [ADR-0015](docs/architecture/adr-0015-base-recipe-for-updates.md)
for why existing sites are updated with the base recipe.

### Web server security

Drupal ships its access rules as `.htaccess` files, which only Apache applies. On any
server, the web root is `web/`, and these must never be served directly:
`settings*.php` and other files under `web/sites/*/` except public files; `*.yml`,
`*.twig`, `*.inc`, `*.install`, `*.module`, `*.profile`, `*.theme`, `*.sql`, `*.sh`;
`composer.json`/`composer.lock`; hidden files (except `/.well-known/`); and PHP in
`web/sites/*/files/`. Keep the private file directory and `vendor/` outside `web/`.

**Apache**: enable `mod_rewrite` and allow the shipped files to apply
(`AllowOverride All` for `web/`). The rules live in `web/.htaccess` and
`web/sites/default/files/.htaccess`.

**nginx** does not read `.htaccess`. A minimal server block:

```nginx
root /var/www/studio/web;
location ~ (^|/)\. { return 403; }                      # hidden files
location ~* \.(engine|inc|install|make|module|profile|po|sh|sql|theme|twig|tpl|xtmpl|yml)$ { return 403; }
location ~ ^/(composer\.(json|lock)|web\.config|core/.*\.txt)$ { return 403; }
location ~ ^/sites/[^/]+/settings.*\.php$ { return 403; }
location ~ ^/sites/[^/]+/files/.*\.php$ { return 403; }  # never execute uploads
location ^~ /.well-known/ { allow all; }
location / { try_files $uri /index.php?$query_string; }
location ~ '\.php$|^/update.php' {
  fastcgi_split_path_info ^(.+?\.php)(|/.*)$;
  include fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
```

**Caddy** does not read `.htaccess` either:

```caddyfile
studio.example.com {
	root * /var/www/studio/web
	encode zstd gzip
	@hidden {
		path /.*
		not path /.well-known/*
	}
	@private path /composer.json /composer.lock /web.config /core/*.txt /sites/*/settings*.php /sites/*/files/*.php
	@source path_regexp \.(engine|inc|install|make|module|profile|po|sh|sql|theme|twig|tpl|xtmpl|yml)$
	respond @hidden 403
	respond @private 403
	respond @source 403
	php_fastcgi unix//run/php/php8.4-fpm.sock
	file_server
}
```

Adapt socket paths and host names to your server, then check that a request for
`/sites/default/settings.php`, `/core/core.services.yml` or `/composer.json` returns 403.

### Test management

People get the roles **QA User** (read plans and their own runs), **QA Tester** (execute
assigned runs, record results and evidence, report QA Issues), **QA Lead** (design plans,
create and assign runs, dashboard and reporting) or **QA Manager** (configuration, reopen
completed runs, delete). They must also see the Development (owner, responsible or
member).

### Project management

**Content editors** work on the tasks of the projects they belong to; **Project Managers**
create projects and subprojects, manage any project they see and see everyone's timesheets
on them. Everyone logs their own time. Add people as members of a
project (or of a parent project) to let them see it.

### Studio Manager

The **Studio Manager** role can do everything in Studio's business logic (records, process,
QA, projects, timesheets, knowledge and their configuration) without being a Drupal site
administrator; it works entirely in the Studio theme.

### MCP (optional)

Exposes Studio, read-only, to AI clients such as Claude Code:

```bash
vendor/bin/drush recipe $PWD/recipes/divengine_studio_mcp
vendor/bin/drush divengine:mcp-setup --uri=https://studio.example.com
```

The second command creates the `mcp_bot` account and a token in
`private/divengine-mcp.token` (outside the web root, ignored by git) and prints the
`claude mcp add` command. See `web/modules/custom/divengine_mcp/README.md`.

To leave MCP out entirely, skip both commands and run
`composer remove drupal/mcp drupal/jsonapi_schema`.

## Checks and tests

[docs/development.md](docs/development.md) describes the development setup and every
kind of test. Run them against a dedicated, disposable PostgreSQL database, never one
that holds data you want to keep.

```bash
scripts/test.sh                # the PHPUnit suite (arguments go to phpunit)
scripts/check-install-paths.sh # every installation path, each in a new database
scripts/check-install.sh       # only the installation profile path
scripts/check.sh               # the suite, then every installation path, before a commit
```

`scripts/test.sh` needs `phpunit.xml`: copy `phpunit.xml.dist` and set `SIMPLETEST_DB`
(`phpunit.xml` is ignored by git). The Functional tests, including the installer
profile's, need a web server for the test site (`SIMPLETEST_BASE_URL`).

No script resets or reuses an existing database:

- `test.sh` uses `SIMPLETEST_DB` (from the environment, or from `phpunit.xml`). Point it
  at an empty database created for the tests. `scripts/test-db-guard.php` refuses the
  database of a site configured under `web/sites/` and any database that already holds a
  Drupal installation; PHPUnit only creates tables with a random test prefix there.
- `check-install-paths.sh` creates a database named `studio_installcheck_<path>_<random>`
  and a throw-away site directory for each path, and removes only what it created. The
  paths are the installation profile with and without the MCP integration, the base recipe
  applied to a Standard site, and, on request, `upgrade`: a site set up with the code of an
  earlier commit (`STUDIO_UPGRADE_FROM`, by default `869526c`, the last one on Drupal 11.3)
  and then updated with this checkout as described in "Update an existing site". Each path
  checks requirements, pending entity definition updates, the expected modules, roles,
  configuration, themes and the `/studio` front page.
- `check-install.sh` and `check.sh` take the server from `STUDIO_INSTALLCHECK_SERVER`, or
  from the server part of `SIMPLETEST_DB`.

Every script needs PostgreSQL 16 or later and refuses older servers.

```bash
STUDIO_INSTALLCHECK_SERVER=pgsql://user:password@127.0.0.1:5432 scripts/check-install-paths.sh
STUDIO_INSTALLCHECK_SERVER=... scripts/check-install-paths.sh upgrade
# when that user cannot create databases:
STUDIO_INSTALLCHECK_PG_ADMIN="sudo -n -u postgres" STUDIO_INSTALLCHECK_SERVER=... scripts/check-install-paths.sh
```


The JavaScript is tested with Node.js and jsdom, against the vendored engines and the
CKEditor 5 build of core:

```bash
for m in divengine_modeler divengine_modeler_bpmn divengine_modeler_uml divengine_diagram_embed; do
  (cd web/modules/custom/$m/tests/js && npm ci && npm test)
done
```

Diagram engines are vendored in each notation module (`js/vendor/`) with their license
and a provenance note; nothing is loaded from a CDN.

Every pull request runs these checks in [CI](.github/workflows/ci.yml).

## Contributing and governance

Contributions are welcome. Divengine Studio is maintainer-led: the maintainer
([@rafageist](https://github.com/rafageist)) decides scope, architecture, roadmap,
releases and merges, and reviews every pull request. An issue or pull request is a
proposal, not approved work.

| Document | Purpose |
|---|---|
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to propose changes, coding standards, tests and review |
| [GOVERNANCE.md](GOVERNANCE.md) | Roles, decisions and triage |
| [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) | Expected behavior in the project's spaces |
| [SECURITY.md](SECURITY.md) | Reporting vulnerabilities privately |
| [docs/development.md](docs/development.md) | Local setup, tests and troubleshooting |
| [docs/releasing.md](docs/releasing.md) | Versioning, compatibility and the release checklist |
| [CHANGELOG.md](CHANGELOG.md) | Changes on `main` and known limitations |

To get started, look for issues labelled
[`good first issue`](https://github.com/divengine/studio/labels/good%20first%20issue) or
[`help wanted`](https://github.com/divengine/studio/labels/help%20wanted), and open an
issue before starting a substantial change. Do not report security problems in public
issues.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
