# Iteración 1: privacidad de trazas y retención histórica

Fecha: 19 de octubre de 2026. Alcance autorizado: **C01, C02 y C03** del informe `ad535bc`. Rama: `fix/iteration-1-trace-access-history`. Este documento registra implementación y validación para revisión; no autoriza desplegar ni continuar la Iteración 2.

Complemento posterior: [revisión del PR #21 sobre rendimiento, retención y reproducción de sondas](PR-REVIEW.md). No modifica los resultados de implementación registrados aquí ni el código funcional de `735df0f`.

## Cambios y contratos

| Corrección | Archivos principales | Contrato resultante |
| --- | --- | --- |
| C01 | `divengine_traceability/src/TraceVisibility.php`, hooks y services; `TraceLink::label()`, `TracePanel`, `TraceLinkListBuilder`; sustitución del filtro parcial en `divengine_process.module` | Una traza solo es accesible si ambos extremos existen y su handler permite verlos para esa cuenta. No basta el permiso global de trazas. Queries con access check y SQL con tag `trace_link_access` filtran antes de contar/paginar. Etiquetas y paneles no renderizan información privada. |
| C02 | `divengine_traceability/src/Entity/TraceLink.php`, `src/TraceLinkStorage.php` | Guardar una traza existente agrega revisión, incluso si el llamador intenta desactivar `setNewRevision()`. Se conserva id/uuid. Una revisión antigua puede ser origen de un revert, pero no se sobrescribe. Las revisiones forzadas registran cuenta/fecha actuales cuando el llamador no preparó una revisión nueva. |
| C03 | `divengine_process/src/Storage/BaselineRetentionStorageTrait.php`, `BaselineArtifactStorage`, `BaselineTraceLinkStorage`, `divengine_process.module`, `Entity/Baseline.php` | Storage rechaza borrar revisiones o registros capturados y sobrescribir una revisión protegida sin crear otra. Comprueba todo el lote antes de borrar. Baseline no es eliminable, pues borrarla liberaría la retención. Purge/finalizePurge no eliminan valores de campos pertenecientes a revisiones capturadas. |
| Diagnóstico | `scripts/diagnose-baselines.php` | Lectura consistente, transacción PostgreSQL read-only, salida solo de IDs/conteos. No carga/guarda entidades ni repara información. |
| Pruebas | `TraceVisibilityTest`, `TraceAccessHttpTest`, fixture de transporte MCP; `BaselineFixtureTrait`, `TraceHistoryTest`, `BaselineRetentionTest`; dependencias `require-dev` en Composer | Pruebas negativas y positivas sobre permisos, consultas, transporte y persistencia reales. Las sondas anteriores se adaptan al comportamiento seguro esperado; el material de auditoría original permanece como evidencia de la versión previa. |

Paths completos en el [inventario de cambios](evidence/changed-files.txt). Las clases de almacenamiento extienden storage SQL de Drupal; Process añade retención sobre `TraceLinkStorage`, sin dependencia inversa ni una entidad/contexto nuevo. No se modificaron ADR, recipes, wiki, website o configuración funcional instalada.

### C01: una única política para todos los extremos

`TraceVisibility` consulta el acceso real de entidades, incluyendo Project/Task, artículos con `kb_development` y restricciones de QA más estrechas que la membresía de Development. El access hook devuelve **forbidden** cuando falta un extremo o no se puede ver, de manera que otro grant no lo convierta en permitido. Se comprueba también acceso a revisiones y operaciones sobre la traza. Las referencias circulares de trazas fallan de forma cerrada.

El filtro de queries usa los tipos/IDs referenciados y cargas por lotes de 100 para delegar en esos handlers. No reutiliza listas de IDs permitidos entre consultas. Esto permite filtrar conteos y páginas completos sin crear un framework de ámbitos ni replicar manualmente las reglas de cada módulo.

Se propagan metadatos de acceso al render de paneles/listados, con contexto de usuario/permisos y dependencias de extremos. Cambiar contenido, miembros o contextos invalida `trace_link_list`, incluyendo respuestas de colecciones vacías que no tienen entidades devueltas para aportar tags. La invalidación es conservadora: puede reducir la eficiencia de caché, pero no mantiene respuestas privilegiadas antiguas.

`load()` y queries internas con `accessCheck(FALSE)` siguen siendo APIs confiables de Drupal; no se convierten en una frontera de autorización automática. Los consumidores expuestos deben usar access checks. Las respuestas HTTP y el lector MCP probado lo hacen.

### C02: historial por adición

La entidad marca revisión nueva durante presave, y storage lo vuelve a exigir inmediatamente antes de persistir, después de hooks. Así no depende del formulario ni de que cada escritor recuerde el flag. Esto se aplica a todas las trazas, con o sin Process instalado. Incluso un save sin cambios crea revisión; no se intenta inferir qué cambios merecen historia.

Las pruebas cambian rationale, tipo y destino; intentan guardar una revisión vieja con el flag desactivado; guardan mediante el objeto de formulario; y realizan un revert mediante la API de revisiones. En todos los casos, las revisiones anteriores y los triples de la baseline conservan su contenido e identidad. Trace Link no es traducible en la definición actual: no se añadió soporte de traducciones ni se afirmó haberlo probado.

### C03: protección antes de la mutación

Drupal invoca `revision_delete` después de borrar valores; un hook posterior no puede garantizar retención. Por eso Process configura handlers derivados de storage SQL y comprueba referencias de baselines antes del borrado. La protección no concede excepciones por administración, sincronización o permisos de UI.

La misma capa rechaza escrituras sobre una revisión protegida cuando el escritor, por ejemplo durante sincronización, evita la revisión nueva habitual de Content Moderation. Un guard de lote evita borrar primero registros no protegidos y fallar después. El presave de Baseline no cambia; siguen permitidas sus ediciones de nombre/notas. Su preDelete impide eliminar la cabecera y, con ella, los items que protegen revisiones.

El purge de campos comprueba valores realmente presentes en tablas de revisiones dedicadas contra los items capturados. Un campo/bundle no capturado puede purgarse. Si hay valores protegidos, se rechaza antes de callbacks que puedan borrar evidencia o de eliminar la tabla. Esto retiene datos; **no congela configuración ni reconstruye definiciones de campos eliminadas**.

## Diagnóstico histórico de solo lectura

Se ejecutó primero, antes de las pruebas que modifican fixtures, sobre el sitio existente de la VM. El script usa una conexión propia y transacción `REPEATABLE READ, READ ONLY`, evitando los savepoints por statement del driver para configurar el aislamiento. Todas sus consultas de diagnóstico son lecturas; termina con rollback explícito.

| Momento | Baselines | Items | Incidencias detectables |
| --- | --- | --- | --- |
| Antes de implementar/probar | 1 | 3 | Ninguna |
| Comprobación posterior del sitio sin desplegar | 1 | 3 | Ninguna |

Resultados completos: [antes](evidence/baseline-diagnostic-before.json) y [después](evidence/baseline-diagnostic-after.json). La segunda salida incluye los valores reales de PostgreSQL para `transaction_read_only` e aislamiento. La prueba automatizada del script también los verifica y confirma que reportar una referencia deliberadamente dañada no la repara.

El diagnóstico comprueba cabeceras huérfanas, items duplicados, entidades/revisiones ausentes, identidad de revisión, contexto del artefacto y pertenencia de los extremos de la traza al conjunto. Señala conjuntos vacíos y timestamps posteriores como casos para revisión, sin declararlos corrupción automáticamente.

**Límite forense:** no hay hashes históricos o copias independientes para demostrar que una revisión nunca se sobrescribió antes del diagnóstico. Cero incidencias significa que no se encontraron inconsistencias detectables con los datos actuales; no certifica ausencia de pérdida anterior. No se reconstruyó, eliminó ni alteró contenido histórico del sitio.

## Validación y evidencia de aceptación

Los resultados finales y los casos JUnit están en [evidence/results.json](evidence/results.json) y [evidence/junit-summary.json](evidence/junit-summary.json). La suite Kernel completa conserva los casos existentes y agrega las regresiones de esta iteración. La prueba Functional se ejecuta explícitamente, porque la configuración principal selecciona Kernel.

| Ejecución final | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Suite Kernel general | 151 | 2.746 | Salida 0; sin errores, fallos u omitidas; 14 deprecaciones reportadas. |
| Regresión adicional de cascada C03, añadida después sin cambiar código funcional | 1 | 35 | Salida 0; sin errores/fallos; 8 deprecaciones reportadas. |
| Functional JSON:API + lector MCP | 1 | 38 | Salida 0; sin errores/fallos; 17 deprecaciones reportadas. |

Son **153 casos distintos y 2.819 aserciones** entre esas ejecuciones. La suite general no se describe como una ejecución de 152 casos: la nueva prueba de cascada se validó por separado después. Las ejecuciones enfocadas anteriores (7 casos/323 aserciones y purge individual) también pasaron, pero no se suman de nuevo al total. No se detectaron regresiones de comportamiento en las ejecuciones finales.

| Criterio | Evidencia automatizada |
| --- | --- |
| C01: outsider no ve título/relación/rationale; acceso positivo conserva funcionalidad | `TraceVisibilityTest::testPrivateEndpointsAreAbsentFromAccessQueriesAndReferences`: Project, Task, Article, QA run; propietario frente a outsider con los mismos permisos generales. |
| C01: conteos, paginación y cuenta explícita | La misma prueba: count de Entity Query, SQL tag como Views, range de una fila y metadata `account` distinta del usuario actual. Las filas ocultas no consumen la página. |
| C01: caché y transporte | `TraceAccessHttpTest::testPrivateTraceAndMembershipChanges`: JSON:API individual/colección, canonical 403, lector MCP real, respuesta accesible calentada antes de revocar membresía y acceso denegado después. |
| C02: edición directa, desactivación del flag y contenido de baseline | `TraceHistoryTest::testEditsPreserveCapturedRationaleTypeAndEnds`: revisión nueva con mismo id/uuid; contenido original intacto y items idénticos. |
| C02: formulario y revert | `TraceHistoryTest::testRevertAndFormSaveAppendHistory`: llamada al save del formulario y API de revert crean revisiones nuevas; tanto original como edición intermedia son recuperables. No es una prueba de navegador del formulario completo. |
| C03: revisiones protegidas vs no protegidas | `BaselineRetentionTest::testProtectedAndUnprotectedRevisionDeletion`: Artifact y Trace, borrado directo rechazado, revisión no protegida no default borrable, baseline intacta. |
| C03: entidades/lotes y cabecera | `testEntityAndBatchDeletionPreserveAllRecordsAndItems`: no se borra parte del lote, no se borra Baseline, sí se borra entidad no capturada; nombre/notas editables. |
| C03: escritor de sincronización | `testProtectedArtifactCannotBeOverwrittenDuringSync`: storage rechaza la sobrescritura y conserva default y revisión original. |
| C03: borrado en cascada | `testEndpointDeletionRollsBackIfCascadeWouldDeleteProtectedTrace`: borrar un extremo posterior no puede eliminar indirectamente una traza protegida; la eliminación completa se revierte. |
| C03: purge de campos | `testFieldPurgeRetainsCapturedValuesButAllowsUnprotectedValues`: retención en purge/finalize y caso positivo de valores no capturados. |
| Diagnóstico sin reparación | `testReadOnlyDiagnosticReportsMissingHistoryWithoutRepair`: fixture corrupto solo en base temporal; reporte de revisión ausente, aislamiento read-only y filas idénticas antes/después. |

MCP se prueba mediante el plugin JSON:API distribuido, ejecutado con la sesión HTTP por un módulo **solo de pruebas**. No se instala ese módulo en la VM desplegada ni en recipes. No se afirma haber probado token provisioning, transporte completo MCP/JSON-RPC o todas las herramientas de Source/Database/CodeDocs: corresponden a otros contratos, fuera de esta iteración.

## Reproducción y entorno aislado

Se usaron copias de código y dependencias bajo `/tmp/studio-iteration1-JVEdkN`, nunca el docroot desplegado para aplicar los parches. Los runners crean una base nueva `studio_it1_<uuid>` por ejecución, pasan su DSN solo por entorno y la eliminan al terminar. El servidor HTTP de pruebas escucha únicamente en loopback y tiene docroot temporal.

Comandos equivalentes desde una copia aislada con PostgreSQL desechable ya creado y `SIMPLETEST_DB` configurado exclusivamente para él:

```sh
vendor/bin/phpunit -c phpunit.xml.dist --no-progress --do-not-cache-result \
  --testsuite kernel --log-junit /tmp/kernel.xml

# Con un servidor HTTP apuntando al web/ de esa misma copia y base desechable:
mkdir -p /tmp/studio-it1-browser-output
SIMPLETEST_BASE_URL=http://127.0.0.1:18092 \
BROWSERTEST_OUTPUT_DIRECTORY=/tmp/studio-it1-browser-output \
  vendor/bin/phpunit -c phpunit.xml.dist --no-progress --do-not-cache-result \
  web/modules/custom/divengine_traceability/tests/src/Functional/TraceAccessHttpTest.php
```

Para el diagnóstico, ejecutable sin copiar correcciones funcionales al sitio:

```sh
vendor/bin/drush php:script /ruta/al/script/diagnose-baselines.php
```

Composer incorpora solo cinco paquetes nuevos de desarrollo: Mink, su BrowserKit driver y componentes BrowserKit/DOM/CSS de Symfony 7.4. La lista bloqueada de paquetes de runtime y las versiones de desarrollo previamente existentes permanecen idénticas. No se instalaron dependencias en el sitio desplegado. `composer install --no-dev` no incorpora esos drivers.

Al finalizar se verificó que no queda ninguna base `studio_it1_%` y se detuvieron ambos servidores temporales por su PID y docroot comprobados. Las copias/logs de `/tmp` se conservan para inspección; no se ejecutó borrado recursivo sobre el workspace ni sobre datos del sitio.

Hubo intentos de validación no válidos antes de completar el entorno: driver Mink ausente, resolución de raíces a través de symlinks y una instalación temporal de Composer con plugins deshabilitados que duplicó Core. Esa última ejecución produjo errores de redeclaración y **no se considera un resultado de regresión del producto ni una suite superada**. Se corrigió el entorno con una copia física y rutas de instalación Drupal normales, y se repitieron las pruebas sobre bases nuevas.

La ejecución inválida con Core duplicado produjo 150 errores de entorno. También se corrigió la preparación del field `body` en el fixture Kernel de artículos. Estas incidencias y los resultados finales se distinguen expresamente en `results.json`; no se ocultaron como pruebas omitidas o superadas.

Se mantienen avisos de deprecación de Core/Symfony/contrib. La prueba Functional acepta únicamente el texto conocido de la deprecación PHP 8.4 de `jsonrpc::access()` si se imprime durante instalación; PHPUnit sigue registrándola. No se desactivaron globalmente los checks de output ni se parcheó vendor para ocultar avisos. Los resultados no deben describirse como libres de deprecaciones.

## Compatibilidad y límites para la revisión

* No hay DDL, update hook, migración ni backfill. Tras un eventual despliegue **aprobado**, las nuevas definiciones de handlers/servicios requieren rebuild de caché. No se ejecutó ese despliegue ni ningún cambio de módulos en el sitio actual.
* El permiso de administrador de trazas ya no permite conocer extremos privados por sí solo; cuentas MCP y operadores necesitan el acceso real a esos extremos. No se concedieron permisos/membresías adicionales automáticamente.
* Cada save de una traza existente genera revisión, incluso sin cambios; escritores que dependan de sobrescribir una revisión deben adaptarse. id, uuid y referencias lógicas no cambian.
* Eliminar baselines queda prohibido también por API, incluso vacías. No hay bypass administrativo. Un proceso de limpieza/desinstalación que dependa de borrar historia necesita revisión explícita de retención, no saltarse el guard.
* El borrado de un extremo no capturado también puede ser rechazado si su cascada borraría una traza con historia protegida. No se deja una eliminación parcial ni se destruye la traza para completar la operación.
* Purges de campos con valores capturados fallan de forma cerrada; un cron de limpieza puede informar esa excepción. No se restaura configuración ni se amplía la baseline a un snapshot integral.
* Otros módulos que sustituyan los mismos handlers de storage necesitarán composición compatible. Esta distribución usa handlers derivados de las clases Drupal existentes y de `TraceLinkStorage`.
* Los access checks por lotes y la invalidación conservadora tienen coste. Se verificó corrección con fixtures, no capacidad máxima ni rendimiento a gran escala. No se implementó C14.
* La captura atómica y coordinación entre capturas/borrados concurrentes siguen siendo C04 y no se implementaron ni se declararon verificadas. Se protege la historia ya referenciada al evaluar la operación de storage.
* SQL directo, eliminación manual de tablas o código con control del esquema quedan fuera de las garantías de Entity API. La prueba del diagnóstico usa SQL deliberado únicamente en su base desechable para representar daños previos.

## Cierre de alcance

Commits para revisión:

| Commit | Contenido |
| --- | --- |
| `fc45d0a` | Diagnóstico previo de solo lectura y evidencia inicial de la VM. |
| `f26fe60` | C01: acceso de extremos, queries, referencias y pruebas Kernel/HTTP/MCP. |
| `e5e360d` | C02: revisiones nuevas de trazas y conservación de historia en ediciones/reverts. |
| `6ffaee5` | C03: retención en storage, baselines y purge, con regresiones positivas/negativas. |
| `4f842c4` | Dependencias exclusivamente de desarrollo para repetir las pruebas HTTP. |
| `b59dfd9` | Regresión adicional C03 de rollback de cascadas. |

No se fusionó la rama a `main`, no se desplegaron correcciones y no se reiniciaron servicios del sitio. El checkout desplegado sigue en `b6607ae` y limpio; `ad535bc` solo añadió el informe anterior. No se iniciaron C04–C17 ni se adoptaron propuestas de ADR. La Iteración 1 termina para revisión con este informe y sus evidencias.
