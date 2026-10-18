# Divengine Studio

Drupal 11 distribution with Divengine's custom modules for managing artifacts,
traceability, knowledge and engineering diagrams, packaged as recipes (ADR-0006).

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
| `recipes/divengine_studio` | Site recipe: Standard + all Studio modules, Gin, diagram embedding in Basic/Full HTML, editor permissions |
| `recipes/divengine_studio_mcp` | Optional recipe: read-only MCP server (drupal/mcp + JSON:API) with token auth |
| `docs/` | Architecture decisions, roadmap, MVP scope, research and handoff notes |
| `scripts/codedocs-demo/` | Runnable PHP and TypeScript shop examples, extraction/import scripts, MCP reads and screenshot capture |
| `composer.json`, `composer.lock` | Exact Drupal core and contrib versions |
| `phpunit.xml.dist`, `bootstrap.kernel.php` | Test configuration |

## Direction

Studio is becoming an engineering suite: explicit artifacts and traces, diagram modelers
(BPMN, UML, Mermaid, DMN, CMMN, IFML, ArchiMate, Excalidraw) whose elements link to Drupal entities, and a
Unified Process layer. See [docs/roadmap.md](docs/roadmap.md) and the ADRs in
[docs/architecture](docs/architecture).

## Setup

Requires PHP 8.4, PostgreSQL (with the `pg_trgm` extension) and `git` (for Code Source). Test evidence is stored
in the private file system: set `$settings['file_private_path'] = '../private/files';` in
`settings.php` (or `settings.local.php`) before installing.

```bash
composer install
vendor/bin/drush site:install recipes/divengine_studio \
  --db-url=pgsql://user:password@localhost/studio --account-name=admin
```

That installs the complete suite: Standard, every Studio module, Gin as the admin theme
and the "Diagram" button with the "Embed diagrams" filter in Basic and Full HTML. On an
existing Drupal 11 site, apply it instead with `drush recipe $PWD/recipes/divengine_studio`.

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

## Checks

Two scripts for you and for agents working on the repository:

```bash
scripts/test.sh             # the PHPUnit suite (arguments go to phpunit)
scripts/check-install.sh    # clean install from the recipe in the test database, with checks
scripts/check.sh            # both, before a commit
```

`check-install.sh` uses `SIMPLETEST_DB` from `phpunit.xml` (or `STUDIO_TEST_DB`), empties that
database, installs into a throw-away `sites/recipecheck` and removes it afterwards.

## Tests

Copy `phpunit.xml.dist` to `phpunit.xml` and set `SIMPLETEST_DB` to your database. `phpunit.xml` is ignored by git.

```bash
vendor/bin/phpunit -c phpunit.xml web/modules/custom
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

## License

GPL-3.0. See [LICENSE](LICENSE).
