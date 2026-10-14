# Divengine Studio

Drupal 11 site with Divengine's custom modules for managing artifacts, knowledge and traceability.

This repository only tracks Divengine's own code. Drupal core, contributed modules and
`vendor/` are installed with Composer.

## Contents

| Path | Purpose |
|---|---|
| `web/modules/custom/divengine_core` | Shared services, interfaces, constants and permissions |
| `web/modules/custom/divengine_artifact` | Artifact entities and artifact types |
| `web/modules/custom/divengine_knowledge` | Knowledge configuration |
| `web/modules/custom/divengine_traceability` | Trace links between artifacts |
| `docs/` | Architecture decisions, MVP scope, research and handoff notes |
| `composer.json`, `composer.lock` | Exact Drupal core and contrib versions |
| `phpunit.xml.dist`, `bootstrap.kernel.php` | Test configuration |

## Setup

```bash
composer install
cp web/sites/default/default.settings.php web/sites/default/settings.php
# configure $databases in settings.php (PostgreSQL), then:
vendor/bin/drush site:install --existing-config   # or import a database dump
vendor/bin/drush en divengine_core divengine_artifact divengine_knowledge divengine_traceability
```

## Tests

Copy `phpunit.xml.dist` to `phpunit.xml` and set `SIMPLETEST_DB` to your database. `phpunit.xml` is ignored by git.

```bash
vendor/bin/phpunit -c phpunit.xml web/modules/custom
```

## License

GPL-3.0. See [LICENSE](LICENSE).
