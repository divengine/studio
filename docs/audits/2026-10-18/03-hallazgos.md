# Defectos y riesgos

Base de evidencia: commit y entornos del [índice](README.md). Los paths son relativos a Studio salvo indicación de otro repositorio. `Confirmado` significa reproducción o evidencia directa del código; `riesgo` señala un escenario todavía no ejecutado. Los IDs Cxx remiten a acciones del [backlog](06-backlog.md).

## F01 · P0 · Exposición indirecta de un proyecto privado — C01

**Confirmado con sonda.** Un usuario con `view trace links`, sin acceso al proyecto, obtiene acceso a la traza y la encuentra mediante `accessCheck(TRUE)`. `TraceLink::label()` incluye el nombre del proyecto privado.

Evidencia exacta:

* `web/modules/custom/divengine_traceability/src/TraceLinkAccessControlHandler.php:14`, `checkAccess()`: permite lectura por permiso global, sin comprobar extremos.
* `.../src/Entity/TraceLink.php:91`, `label()`: resuelve etiquetas actuales de ambos extremos.
* `web/modules/custom/divengine_process/divengine_process.module:176`, `divengine_process_trace_link_access()`: solo considera extremos con campo `development` no vacío.
* `web/modules/custom/divengine_process/src/Access/DevelopmentVisibility.php`, `alterTraceQuery()`: filtra tipos de ese ámbito, no la política de Project ni todas las restricciones de QA/artículos.
* [Resultado](evidence/trace-evidence.jsonl): `project_visible=false`, `trace_visible=true`, `trace_in_access_filtered_query=true`, etiqueta `[AUDIT] Private project title references RSK-0001`.

La exposición del nombre y de la relación es real en el nivel de entidad/query. No se ejecutó un exploit HTTP contra el sitio ni se afirma que cada herramienta MCP serialice ese campo concreto. JSON:API/MCP deben incluirse en la regresión por reutilizar entidades y políticas.

Además, `TracePanel::build()` renderiza relación y rationale de filas consultadas; solo condiciona el enlace Details al acceso individual. Ocultar el extremo con «restricted» no basta si la propia relación es confidencial. El caché del resultado debe depender también de los extremos/contextos. La corrección necesita tanto query access como entity access y consumidores de referencias.

## F02 · P0 · Una traza histórica cambia dentro de la misma revisión — C02

**Confirmado con sonda.** Se capturó una baseline con una traza, se cambió `rationale` y se guardó sin `setNewRevision()`. El ID de revisión no cambió y `loadRevision()` devolvió el rationale nuevo, aunque `protectsRevision()` seguía indicando protección.

Evidencia: `web/modules/custom/divengine_traceability/src/Entity/TraceLink.php:104`, `preSave()`, no fuerza revisión nueva ni rechaza cambios a revisiones protegidas. `TraceLinkForm` tampoco establece una política obligatoria equivalente a la moderación de artefactos. Resultado `trace_revision_overwrite` en [JSONL](evidence/trace-evidence.jsonl).

La entidad es revisionable, pero eso no garantiza inmutabilidad. La baseline pierde contenido histórico aun conservando el mismo triple de identificación. Deben cubrirse rationale, tipo y extremos, además de reverts/imports. Cambiar solamente el formulario dejaría abierto el save directo.

## F03 · P0 · Storage permite eliminar una revisión protegida — C03

**Confirmado con sonda.** Tras crear una baseline y una revisión posterior del artefacto, `deleteRevision($protected_revision)` eliminó la revisión que la baseline todavía referencia.

Evidencia: `web/modules/custom/divengine_process/divengine_process.module:216`, `divengine_process_baseline_protection()` prohíbe operaciones mediante access hooks; no es una barrera de almacenamiento. Resultado `baseline_history`: `was_protected=true`, `protected_revision_exists_after_delete=false` en [JSONL](evidence/probe-evidence.jsonl).

`Baseline::postDelete()` elimina sus elementos y la entidad permite `delete()` directo. La sonda de baseline vacía demuestra esa vía, pero no prueba por sí misma una pérdida histórica: la pérdida reproducida es la revisión de artefacto. Debe definirse expresamente si se admite eliminar baselines y cómo se preserva una retención acordada.

Drupal storage no invoca access checks automáticamente: esto es una ruptura del contrato de historial exigido por ADR-0007 frente a código PHP con capacidad de escritura, no evidencia de un endpoint anónimo de borrado. La protección debe actuar en el punto soportado de eliminación de revisiones, no solo en UI.

## F04 · P1 · Captura y transición de milestone sin atomicidad conjunta — C04

**Confirmado por inspección; no se inyectó un fallo concurrente.** `BaselineRecorder::take()` consulta artefactos/trazas, guarda la cabecera (`:65`) y después inserta elementos (`:66–72`) sin transacción ni coordinación de captura. Un fallo del insert deja cabecera persistida sin el conjunto esperado. Lecturas sucesivas también pueden observar cambios concurrentes sin una política consistente.

`web/modules/custom/divengine_process/src/Lifecycle/LifecycleManager.php`, `apply()`, guarda la entidad antes de ejecutar `$transition->after`; `Milestone::takeBaseline()` es un efecto posterior. Puede persistir el milestone alcanzado aunque falle la captura. La transacción interna de un save Drupal no abarca automáticamente estas operaciones separadas.

Una baseline de cero elementos se admite hoy. `DevelopmentTest::testMilestoneReachIsAdvisory()` confirma el carácter consultivo del readiness: no se propone convertirlo en bloqueo ni prohibir todos los conjuntos vacíos. Hay que distinguir vacío válido de captura incompleta. Añadir transacción sin decidir consistencia de lectura y coordinación tampoco basta para concurrencia.

## F05 · P1 · Las constraints no garantizan las transiciones en escrituras directas — C05

**Confirmado con sonda.** `ProcessActivity` rechaza mediante `validate()` pasar directamente a `completed`, pero `save()` persiste ese mismo estado. Una traza entre dos Developments también presenta violación y se guarda. Esto es comportamiento normal de Entity API si el llamador omite validar; contradice la promesa local de invariantes en todas las escrituras.

Evidencia: `ProcessStateTransitionConstraintValidator::validate()`, `ProcessTraceConstraintValidator::validate()` y las sondas `direct_state_save` / `cross_development_trace` en [JSONL](evidence/probe-evidence.jsonl). `StatefulTrait::setStateThroughTransition()` es público y marca la operación como transición; no constituye un mecanismo de autorización. `LifecycleManager::apply()` valida pero no verifica permisos del llamador; los controllers tienen esa responsabilidad.

La sonda de artefactos confirma que Core crea revisión nueva para contenido moderado; no hubo sobrescritura de la revisión de artefacto. Sin embargo, guardar cambios manteniendo `published` puede hacer efectiva inmediatamente la nueva revisión. La garantía probada por la suite existente es otra: **si se selecciona draft**, la revisión publicada sigue vigente. Debe acordarse y probarse qué escrituras de contenido baselined requieren volver al workflow. No se propone reemplazar Content Moderation.

Los formularios y JSON:API normalmente validan entidades. No se concluye de estas sondas que puedan saltarse sus validadores por HTTP; la brecha demostrada es de API PHP y contratos de los servicios de escritura.

## F06 · P1 · Código de artefacto mutable — C06

**Confirmado con sonda.** Un artefacto `REQ-0001` se guardó como `CHANGED-BY-DIRECT-SAVE`. La identidad lógica id/uuid permanece; el código humano usado en referencias no tiene igual protección.

Evidencia: `web/modules/custom/divengine_artifact/src/Entity/Artifact.php:95`, `preSave()`, recorta el código y lo genera si falta; `setArtifactKey()` permite reemplazarlo. El constraint de unicidad no comprueba inmutabilidad. El campo revisionable y ocultarlo en un formulario tampoco impiden cambiar un código único. Ver resultado `artifact_key`.

Impacta referencias `[[CODE]]`, enlaces externos e interpretación de revisiones. Hay que separar generación, posible corrección administrativa auditada y estabilidad normal. No renumerar datos existentes silenciosamente.

## F07 · P1 · La eliminación de Development no cubre todos sus registros — C07

**Confirmado por inspección; escenario de borrado no ejecutado.** `DevelopmentAccessControlHandler::RECORDS` contiene solo `artifact`, `process_activity`, `change_request` y `baseline`. QA, Source, Database, CodeDocs y artículos ya pueden pertenecer al contexto, pero no participan en ese guard. `Development::postDelete()` elimina la estructura de fases/iteraciones/milestones/roles, no esos registros de ingeniería.

Un contexto con repositorios/snapshots y sin los cuatro tipos enumerados puede superar ese control. El resultado potencial son referencias huérfanas y pérdida de navegación o acceso. El hook de scoped types existente tampoco cubre artículos mediante el nombre `kb_development`; no basta reutilizarlo sin adaptar el contrato. Se requiere una prueba de cada integración y política explícita de retención. No se propone borrar en cascada conocimiento para resolver referencias.

## F08 · P1 · Contrato de extremos y escrituras incompleto — C08

**Confirmado por inspección; algunas vías pendientes de prueba.** `TraceLinkValidator::validateAll()` comprueba existencia, distinción, compatibilidad y duplicado. No recibe cuenta ni verifica visibilidad de extremos. `ProcessTraceConstraintValidator::development()` reconoce el propio Development o un campo literal `development`; no `kb_development`. Las constraints entre contextos no son por tanto una garantía genérica para todos los tipos registrados.

Las restricciones de ciclos se aplican a `includes` y `generalizes`, deliberadamente no a todas las relaciones: `extends` puede volver. `impacts` exige origen change request en contextos UP. No hay que prohibir ciclos universalmente. La comprobación de duplicados es consulta previa, no garantía concurrente de unicidad. No se reprodujo una carrera de duplicados; queda como riesgo específico.

La escritura mediante PHP requiere un contrato claro de llamador confiable, autorización, validación e invariantes persistentes. Los endpoints de importación CodeDocs/Database sí tienen permisos y validación de contrato; ese patrón debe verificarse con pruebas negativas antes de generalizar una API nueva. MCP/JSON:API de lectura no justifican afirmar que existe una vía externa de escritura hoy.

## F09 · P1 · Validación automatizada desigual — C09 y C10

**Inventario y ejecución confirmados.** Hay 22 clases Kernel en siete módulos y un runner separado en CodeDocs. Diez módulos no tienen pruebas propias encontradas: Core, Knowledge, QA, Project, Timesheet, Thread, Articles, Source, Database y MCP. Pueden participar como dependencia en otras pruebas; eso no valida su comportamiento específico.

No se encontraron clases Functional/FunctionalJavascript ni pruebas PHPUnit Unit separadas en los módulos propios. Las cuatro suites JS no se ejecutaron por dependencias ausentes. No hay evidencia nueva de actualización o instalación limpia. La matriz detalla escenarios faltantes, incluido acceso de referencias y retención histórica. Fixtures de Database no equivalen a assertions sobre una importación real.

## F10 · P1 · Script de instalación puede vaciar una base indicada por error — C11

**Confirmado por inspección; no ejecutado.** `scripts/check-install.sh`, `reset_db()`, ejecuta `DROP SCHEMA public CASCADE` sobre el DSN entregado por `STUDIO_TEST_DB` o `phpunit.xml`. No verifica que sea una base desechable creada por la propia ejecución ni que difiera de la del sitio. `cleanup()` vuelve a invocarlo, incluso desde el trap.

El comentario advierte que vacía la base, pero el nombre «throw-away» no garantiza aislamiento. La auditoría creó bases nuevas en su lugar. Los checks del script también omiten CodeDocs y su rol de publicación; MCP requiere comprobarse como recipe opcional, no exigirlo en la base.

## F11 · P2 · Documentación y madurez exageran garantías — C12

La [matriz](02-coherencia.md) identifica por documento las diferencias: historial irrompible, extremos visibles, módulo QA «completo», snapshot al iniciar run, mapa incompleto y terminología Published/Baselined. Son afirmaciones que necesitan corrección o evidencia adicional, no un motivo para declarar inexistentes los módulos implementados. El roadmap interno `docs/roadmap.md` mezcla prestaciones ya presentes con otras previstas bajo una fase marcada terminada; debe reconciliarse antes de trasladarlo a compromisos públicos de release.

## F12 · P2 · Extensiones y límites de módulos sin contrato completo — C13

`DevelopmentViewBuilder` construye tarjetas concretas pese a ADR-0007; Core conoce una ruta de findings Database en `PageElementsTrait`; Thread conoce una lista central de hosts opcionales. Hay hooks y managers útiles, pero no un catálogo de contratos soportados, política de compatibilidad o distinción público/interno. Resolver primero la extensión concreta; no agregar buses de eventos ni interfaces sin consumidor.

Development como contexto compartido es una decisión pendiente: [propuesta](propuestas/contexto-ingenieria.md). La separación de Project y el ámbito global de Diagram están aceptados en ADR-0009/0004 y deben preservarse salvo revisión explícita. CodeDocs carece de ADR propio equivalente a Source/Database; [propuesta](propuestas/codedocs.md), todavía no aceptada.

## F13 · P2 · Escalabilidad y concurrencia pendientes de medir — C14

`ProcessTraceConstraintValidator::reaches()` recorre el grafo con una consulta de salidas por nodo visitado. `CodeDocIndex` tiene trabajo por elemento y materializa mapas durante finalización/diff; Database también carga facts para comparar snapshots. No se realizó benchmark: no se afirma un límite de tablas/símbolos soportado ni un fallo de rendimiento demostrado.

Hay transacciones en `database/SnapshotImporter::import()` y locks/transacciones en `codedocs/SnapshotImporter::append()/finish()`, aspectos positivos que no deben atribuirse a BaselineRecorder. Quedan por medir cargas concurrentes, duplicación por identidad, recuperación tras error y coste de consultas bajo permisos. Optimizar a partir de esos perfiles, conservando acceso y semántica.

## F14 · P2 · Deprecaciones y entorno de pruebas — C15

La suite pasa con 12 categorías de deprecación: servicios de caché antiguos, opciones de constraints Symfony, `Request::get()`, llamadas de fetch y argumentos numéricos de Views. Parte pertenece a Core/dependencias; los Views entregados por Process y usos propios deben corregirse localmente. No editar vendor para silenciar resultados. La configuración no falla por toda deprecación, de ahí salida 0 con incidencias.

El runner CodeDocs requiere SQLite; la VM carece de `Pdo\Sqlite`. El resultado local de 41 checks no reemplaza integración PostgreSQL, rutas autenticadas o MCP. Falta un preflight que identifique esas diferencias de forma reproducible. Conviene revisar dependencias de servicios opcionales, por ejemplo `path_alias.manager` en el modelador y Basic Auth para importar Database, en instalaciones mínimas; no se reprodujo un fallo de instalación mínima en esta auditoría.

## F15 · P1 · Requisito PostgreSQL incompatible con Core — C16

**Confirmado por código y documentos locales.** `web/core/modules/pgsql/src/Driver/Database/pgsql/Install/Tasks.php:26` fija `PGSQL_MINIMUM_VERSION = '16'`. La wiki `Installation.md:22` y el FAQ del website `_build/pages/index.html:1` indican PostgreSQL 14 o superior. Quien siga esa recomendación puede provisionar una versión que el instalador de Core rechaza. Actualizar requisitos y comprobación previa según la versión bloqueada, sin cambiar la plataforma de datos.
