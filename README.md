# Divengine Studio

Drupal 11 site with Divengine's custom modules for managing artifacts, traceability,
knowledge and engineering diagrams.

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
| `docs/` | Architecture decisions, roadmap, MVP scope, research and handoff notes |
| `composer.json`, `composer.lock` | Exact Drupal core and contrib versions |
| `phpunit.xml.dist`, `bootstrap.kernel.php` | Test configuration |

## Direction

Studio is becoming an engineering suite: explicit artifacts and traces, diagram modelers
(BPMN, UML, Mermaid, DMN, CMMN, IFML, ArchiMate, Excalidraw) whose elements link to Drupal entities, and a
Unified Process layer. See [docs/roadmap.md](docs/roadmap.md) and the ADRs in
[docs/architecture](docs/architecture).

## Setup

```bash
composer install
cp web/sites/default/default.settings.php web/sites/default/settings.php
# configure $databases in settings.php (PostgreSQL), then:
vendor/bin/drush site:install --existing-config   # or import a database dump
vendor/bin/drush en divengine_core divengine_artifact divengine_knowledge divengine_traceability \
  divengine_modeler divengine_modeler_bpmn divengine_modeler_uml divengine_diagram_embed
```

To embed diagrams, add the "Diagram" button and the "Embed diagrams" filter to a CKEditor 5
text format (see `web/modules/custom/divengine_diagram_embed/README.md`).

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
