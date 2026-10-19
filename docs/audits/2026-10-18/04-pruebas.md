# Inventario y cobertura de comportamientos

Se ejecutó la suite existente sobre una base PostgreSQL nueva, separada de la del sitio, en la VM. El [resumen de resultados](evidence/results.json) y el [inventario](evidence/inventory.json) permiten revisar cifras y clases sin exponer DSN ni credenciales. El resumen JUnit conserva los 143 casos, incluidas variantes de data providers.

## Tipos de prueba encontrados

| Tipo | Inventario | Ejecución de esta auditoría |
| --- | --- | --- |
| PHPUnit Kernel | 22 clases en siete módulos; `phpunit.xml.dist` selecciona `*/tests/src/Kernel` | 143 pruebas, 2.386 aserciones, 0 fallos/errores/omitidas; 12 categorías de deprecación. 457 s según JUnit. |
| PHPUnit Unit independiente | No encontrado en módulos propios | No ejecutado. No llamar Unit a toda la suite Kernel. |
| JS unit/component | Cuatro archivos, con package propio en `tests/js` de Modeler, BPMN, UML y Embed | No ejecutados; faltan `node_modules` en la VM. No se instalaron dependencias nuevas para este informe. |
| Functional / FunctionalJavascript Drupal | No se encontraron clases propias | No ejecutados; una ruta existente en Kernel no prueba una petición HTTP ni navegador. |
| Integración CodeDocs aislada | `divengine_codedocs/tests/run.php`, SQLite `:memory:` con DB API real y adapters | 41 checks locales; VM bloqueada antes de assertions por falta de driver SQLite. |
| Instalación limpia | `scripts/check-install.sh`, chequeos recipe | No ejecutado por el riesgo del DSN y su reset destructivo, F10. |
| Actualizaciones | Hooks existen en módulos; no se encontró suite dedicada de upgrades | No ejecutadas. Instalar esquema en Kernel no equivale a probar upgrade de un sitio. |
| Diagnóstico de auditoría | Dos clases externas derivadas de `DevelopmentTest`, siete métodos `testAudit*` | 7 pruebas, 224 aserciones. Las assertions esperan la conducta defectuosa para documentarla, no certificar corrección. |

CodeDocs utiliza PHP 8.3.7 local con las dependencias runtime y SQLite; no es evidencia de que la distribución completa soporte PHP 8.3. Los tests Drupal de la VM usaron PHP 8.4.26.

## Matriz de comportamientos críticos

Los nombres cortos siguientes corresponden a `web/modules/custom/<módulo>/tests/src/Kernel/<clase>.php`, identificados íntegramente en el inventario.

| Comportamiento | Evidencia existente ejecutada | Lo que todavía falta / acción |
| --- | --- | --- |
| Bundle/código único | Artifact: `UniqueArtifactKeyConstraintTest` | Inmutabilidad del código, cargas simultáneas y corrección administrativa. Sonda confirma cambio; C06. |
| Revisiones y acceso por tipo | Artifact: `ArtifactRevisionAccessTest` | Borrado histórico por storage, endpoints y reverts; C03. |
| Draft posterior conserva default publicado | Artifact: `ArtifactLifecycleTest::testReviewApproveAndDeprecate` | Escritura publicada sin pasar a draft; política en todos los escritores; C05. |
| Transición inválida | Artifact lifecycle comprueba `validate()`; Process `DevelopmentTest` prueba lifecycle | Prueba de rechazo persistente del save directo de Activity, CR, iteración y milestone. Sonda demuestra brecha; C05. |
| Extremos existentes, distintos y tipos admitidos | Traceability: `ValidTraceLinkConstraintTest`, `TraceLinkTypeRulesTest` | Acceso de ambos extremos, JSON:API, paneles y concurrencia de duplicados; C01/C08. |
| Configuración instalada de trazas | Traceability: `InstallConfigTest` | Recipe completa con módulos opcionales y upgrades; C11/C16. |
| Tailoring por copia | Process: `ProcessConfigurationTest`, `DevelopmentTest::testCaseChangesNeverRetailorARunningDevelopment` | Reaplicar explícitamente, concurrencia y compatibilidad entre versiones; C09. |
| Contextos/roles/readiness | Process: `testIterationsStayInTheirDevelopment`, `testRoleAssignmentsAreUniqueAndGrantNoAccess`, `testVisibility`, `testMilestoneReachIsAdvisory` | Todos los tipos externos, query/entity/API y caché tras cambio de miembros; C01/C07/C08. |
| Captura de baseline | Milestone advisory y sondas con artefactos/trazas | Rollback por fallo entre cabecera/items y milestone; capturas simultáneas, revisión pendiente, vacío válido; C02/C03/C04. |
| Historial de traza | Sonda `testAuditTraceRevisionChangesInPlace` | Regresión que espere rationale antiguo tras save/revert. El test diagnóstico actual confirma que se pierde; C02. |
| Proyecto privado no referenciable | Sonda `testAuditPrivateProjectTraceAccess` | Proyecto/tarea/artículo/QA privados, label/rationale/listado/API/MCP y caché; C01. |
| Modelos, conflictos y referencias | Modeler: `DiagramEntityTest`, `DiagramAccessTest`, `DiagramModelSaverTest`, `EntityReferenceResolverTest`, `LinkIndexTest` | Conflicto real entre dos sesiones de navegador y referencias cruzadas privadas; C10. |
| SVG seguro y preview | Modeler: `SvgSafetyTest`, `PreviewBuilderTest` | Regresión browser/CSP e intentos de navegación/descarga; C10. |
| Notaciones | BPMN: `BpmnNotationTest`; UML: `UmlNotationTest` | JS y roundtrip de edición real; C10. |
| CKEditor y embeds | Embed: `CKEditor5DiagramPluginTest`, `DiagramEmbedControllerTest`, `DiagramEmbedFilterTest`, `DiagramUsageTest`, `DiagramUsageTranslationTest` | Navegador y edición de artículo con permisos distintos; C10. |
| CodeDocs adapters, facts, diff y aislamiento | Runner local: 41 checks sobre adapters, queries reales, resolución, cobertura, diff y rollback | `SnapshotImporter` Drupal, begin/append/finish, retries, locks, permisos y publicación incompleta PostgreSQL; C09/C10. |
| QA: copia congelada, ejecución y correcciones | Sin pruebas propias encontradas | Cambiar plan tras crear run, tester ajeno, adjunto privado, corrección auditada y verificación de requisito; C09/C10. |
| Project / Timesheet | Sin pruebas propias encontradas | Jerarquía sin ciclos, privacidad por proyecto, tareas ajenas, timer y grid con concurrencia; C09. |
| Articles / Thread / Knowledge | Sin pruebas propias encontradas | Grants, herencia `kb_development`, comentarios y archivos privados, referencias a hosts inaccesibles; C09/C10. |
| Source / Database / MCP | Sin pruebas propias encontradas; Database tiene fixtures | Git fuera de raíz, commit fijo, import/diff/rollback PostgreSQL, token revocado y acceso negativo a facts; C09/C10. |
| Borrado de Development con registros externos | Inspección de guard y postDelete | Una prueba por tipo integrado, incluidos nodos, que impida huérfanos; C07. |

## Ejecución segura y comandos

En la VM `/home/rafa/workspace/divengine/studio`, un driver temporal creó una base nueva con nombre `studio_audit_<uuid>`, usando un rol de pruebas ya configurado. Se sobreescribió `SIMPLETEST_DB` en el entorno del proceso, sin imprimir su valor. Al terminar, se eliminó **solo esa base creada por la ejecución**. El Kernel usó el esquema/prefix de pruebas habitual. No se ejecutó el reset de `scripts/check-install.sh`.

Comando de la suite (con `SIMPLETEST_DB` apuntando a esa base nueva):

```sh
vendor/bin/phpunit -c /home/rafa/workspace/divengine/studio/phpunit.xml.dist \
  --no-progress --do-not-cache-result \
  --log-junit /tmp/studio-audit-yhl5ubcq/junit.xml
```

Las sondas se ejecutaron en bases nuevas independientes con `--filter testAudit`, la misma configuración y cada archivo externo como argumento. `STUDIO_AUDIT_EVIDENCE` identifica el JSONL de salida; las clases heredan el fixture Process. Sus fuentes se conservan en `evidence/probes/`. No pertenecen a la suite normal ni se deben convertir sin cambios en regresiones: las regresiones de una corrección deben invertir las expectativas defectuosas.

Runner CodeDocs ejecutado desde la raíz, tanto en VM como localmente:

```sh
php web/modules/custom/divengine_codedocs/tests/run.php
```

Salida local: `Code docs: 41 checks passed (adapters, real SQLite schema/queries, resolution, coverage, diff, rollback, isolation, YAML).`

VM: `Class "Pdo\Sqlite" not found` en `web/core/modules/sqlite/src/Driver/Database/sqlite/SqliteConnection.php:32`, salida 255. Es una limitación del entorno, no una assertion funcional fallida. Los logs completos quedaron temporalmente en `/tmp/studio-audit-yhl5ubcq`; se versionan resúmenes y evidencia mínima sin datos de conexión, no se depende de que `/tmp` perdure.

No se generó porcentaje de cobertura de líneas. Ni las 2.386 aserciones ni los 41 checks sustituyen la matriz de invariantes y pruebas negativas.
