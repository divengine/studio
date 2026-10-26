# Changelog

All notable changes to Divengine Studio are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Version
numbers follow the policy in [docs/releasing.md](docs/releasing.md#versioning-and-compatibility).
No release has been published yet; everything below is on `main`.

## Conventions

- Add an entry under **Unreleased** in the same pull request as a user-visible change.
- Group entries under **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed** and
  **Security**. List upgrade steps under **Upgrade notes** and open problems under
  **Known limitations**.
- Write for site operators and users: what changed and what they need to do, with the
  issue or pull request number.
- When a release is approved, the **Unreleased** section becomes
  `## [x.y.z-stage] - YYYY-MM-DD` and a new empty **Unreleased** section is started.

## [Unreleased]

### Added

- Distribution on Drupal 11 with PostgreSQL: artifacts with revisions, moderation and
  per-type permissions; configurable trace link types and trace links between
  artifacts, diagrams and content.
- Diagram modeler platform with BPMN 2.0 (bpmn-js) and UML (PlantUML in the browser)
  notations, safe SVG previews, element links to Drupal entities, and diagrams embedded
  in formatted text through a CKEditor 5 button.
- Unified Process (`divengine_process`): Developments with phases, iterations,
  milestones, roles, business and system model, activities, work products, change
  requests, readiness, baselines and **My work**.
- Test management (`divengine_qa`): plans, suites, cases, steps, runs with frozen
  snapshots, results, evidence, QA Issues, dashboard and reports.
- Project management and time tracking: projects, subprojects, tasks on Kanban boards,
  timesheets, timer and weekly grid.
- Discussions with attachments on every record, a knowledge base with `[[CODE]]` links,
  Code Source (Git repositories), database schema snapshots and evolution, and
  language-neutral code documentation with a publishing API.
- Studio workspace theme and the **Studio Manager** role.
- Optional read-only MCP server (`recipes/divengine_studio_mcp`) with token
  authentication and `drush divengine:mcp-setup`.
- OAuth 2.1 for MCP clients (`divengine_mcp_oauth`, ADR-0017): claude.ai, Claude Desktop,
  ChatGPT and other clients connect by URL, register themselves and act as the person who
  authorizes them, read-only. Adds `drupal/simple_oauth`, the **MCP user** role and
  `drush divengine:mcp-oauth-setup`.
- Native web installer: the `divengine_studio_installer` profile is selected
  automatically by `/core/install.php`, has a branded installer theme, an optional
  MCP integration step and finishes at `/studio`; also usable with
  `drush site:install divengine_studio_installer` (#24, #52, ADR-0014).
- `recipes/divengine_studio_base`, re-applied to update existing sites (#53, #54,
  ADR-0015).
- `scripts/check-install-paths.sh`, which verifies the profile with and without MCP and
  the base recipe on a Standard site, each in a new database (#26, #52).
- Community documents, issue forms, pull request template, security policy and pull
  request CI (#34, #35, #36, #37).

- External references (`divengine_external`, ADR-0018, #69): GitHub issues, pull requests
  and commits, or any web link, registered once per Development and traced to
  requirements, decisions and evidence with **Link external work** in a record's Traces.
  Links are read without network access; references follow the visibility of their
  Development and are readable through JSON:API and the MCP `jsonapi_read` tool.

### Changed

- Drupal core 11.4.10, Gin 5.0.16 and Drush 13.8.0 (#53, #54).
- `recipes/divengine_studio` is now Drupal's Standard recipe followed by
  `divengine_studio_base`, and is only applied to new sites (#54).
- Applying the recipe keeps the site's name (#52).
- Saving an existing trace link always creates a new revision (#21).
- Development, iteration, milestone, activity and change request states change only
  through declared transitions; moderated artifacts follow their workflow (#33).
- Artifact codes are permanent once assigned (#33).
- Deleting a Development that still has dependent records is refused (#33).
- Creating a trace link requires view access to both ends and refuses duplicates,
  cycles in `includes`/`generalizes` and links across Developments (#33).
- QA runs record when their snapshot was taken. A Draft run whose plan has no Ready
  cases says it cannot start until a QA Lead generates its executions (#9).
- Correcting a result of an older QA run keeps its execution date and tester; the
  correction record holds who corrected it, when and why, and the newer run stays the
  case's last result (#9).
- Copying run evidence into a QA Issue is now opt-in, and the form warns that copies are
  shared with the QA users of the Development (#9).

### Fixed

- Trace links no longer reveal private endpoints through access checks, queries,
  listings, labels or cached collections (#21).
- Captured baseline content and revisions can no longer be changed or deleted (#21).
- Milestone transitions and baseline capture are committed or rolled back together
  (#33).
- Clean installations: missing Gin blocks and views, the missing Project task form
  display and invalid knowledge article configuration (#25, #52).
- New installations on Drupal 11.4, whose Standard recipe no longer provides the
  configuration Studio's knowledge articles relied on (#54).
- Cron no longer logs `ServiceNotFoundException: mcp.client_message.storage` on every run
  when the MCP integration is installed: Divengine MCP removes the MCP module's cron
  hook, which still calls the message storage that MCP removed with its SSE transport.

### Security

- Gin updated from 5.0.15 to 5.0.16 for SA-CONTRIB-2026-219 (#54).
- Trace endpoint privacy fixes (#21).

### Upgrade notes

Existing sites are updated with:

```bash
composer install
vendor/bin/drush updatedb --yes
vendor/bin/drush recipe $PWD/recipes/divengine_studio_base
vendor/bin/drush recipe $PWD/recipes/divengine_studio_mcp   # if MCP is installed
vendor/bin/drush cache:rebuild
```

Sites with MCP get OAuth on that update. Run
`vendor/bin/drush divengine:mcp-oauth-setup --uri=https://your-studio-address` once
afterwards, and give the **MCP user** role to the people who will connect AI clients.

Re-applying `divengine_studio_base` installs External references and grants **Link
external work** to the roles that create trace links (#69).

Do not re-apply `recipes/divengine_studio` to an existing site (ADR-0015).

### Known limitations

- **Alpha quality.** There is no published release and no compatibility promise between
  commits on `main` yet.
- Only PHP 8.4 and PostgreSQL 16 or later are tested. PHP 8.3 is accepted by Drupal
  but not tested; Studio requires PostgreSQL and other databases are not tested.
- Sites installed before Drupal 11.4 and new sites differ in what Drupal's Standard
  recipe provided (toolbar, Article and Basic page, History). `divengine_studio_base`
  cannot be applied to a site installed with the Minimal profile.
- Concurrency guarantees for baseline capture, Development deletion and trace writes are
  incomplete and are tracked in #23.
- Trace visibility checks grow with the size of the graph; scaling is to be measured
  in #17.
- CodeDocs publisher permissions and documentation browser queries need bounds (#22).
- Deprecation notices from contributed modules (`key`, `mcp`, `jsonrpc`) and some Studio
  code appear in test output (#18).
- `scripts/check-install.sh` and `scripts/check.sh` empty the test database they are
  given (#14); use a dedicated database or `scripts/check-install-paths.sh`.
- Mermaid, DMN, CMMN, IFML, ArchiMate and Excalidraw notations are planned, not
  implemented.
- The nginx example in the README has not been verified on a running nginx server.

[Unreleased]: https://github.com/divengine/studio/commits/main
