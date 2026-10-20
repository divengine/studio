# Iteración 2: integridad del dominio

Fecha: 20 de octubre de 2026. Alcance autorizado: **C04, C05, C06, C07 y C08** (issues #4–#8). Rama: `fix/iteration-2-domain-integrity`, basada en `f42c24a` (merge del PR #21). Este documento registra implementación y validación; no autoriza desplegar ni iniciar la Iteración 3.

Las condiciones de concurrencia que quedan abiertas están registradas en la issue #23 y se aplazaron por decisión explícita. Este documento **no** las declara resueltas.

## Cambios y contratos

| Corrección | Archivos principales | Contrato resultante |
| --- | --- | --- |
| C04 | `divengine_process/src/BaselineRecorder.php`, `Lifecycle/LifecycleManager.php`, `Entity/Baseline.php`, `divengine_process.install` | Cabecera, items y transición del milestone que dispara la captura se confirman o se descartan juntos. Un fallo inyectado no deja cabecera, items ni milestone alcanzado, y el objeto del llamador vuelve a su estado. Un segundo «alcanzar» sobre un milestone ya alcanzado se rechaza al releer el estado guardado. La cabecera registra `item_count`, de solo lectura, escrito en la misma transacción: cero es una baseline vacía válida. |
| C05 | `divengine_process/src/Lifecycle/StateGuard.php`, `divengine_process.module`, `divengine_artifact/src/Entity/Artifact.php` | Development, iteración, milestone, actividad y change request solo se guardan en un estado declarado y solo cambian de estado mediante una transición declarada, también por API directa. En artefactos moderados, un cambio de `moderation_state` debe existir en su workflow. El contenido aprobado no se edita en sitio: un cambio crea un borrador pendiente y la revisión aprobada sigue siendo la vigente. |
| C06 | `Artifact.php`, `StableArtifactKeyConstraint(Validator)` | El código (`REQ-0001`) no cambia una vez asignado. Storage rechaza el cambio y la validación lo informa en el campo. Un revert conserva el código actual. Un registro sin código aún puede recibir uno. |
| C07 | `divengine_process/src/DevelopmentReferences.php`, `divengine_process.api.php`, `Entity/Development.php`, `Access/DevelopmentAccessControlHandler.php`, `divengine_articles.module` | Un Development con cualquier registro dependiente no se puede eliminar por ningún escritor que use Entity API. Se cuentan Process, QA, Articles (`kb_development`), Source, Database y CodeDocs. La enumeración es extensible con `hook_divengine_process_development_references_alter()`. Un Development vacío se elimina con su estructura: fases, iteraciones, milestones y asignaciones de rol. |
| C08 | `divengine_process/src/TraceRules.php`, `ProcessTraceConstraintValidator`, `divengine_traceability/src/Entity/TraceLink.php`, `TraceEndpointAccessConstraint(Validator)` | Autorización e integridad quedan separadas. Crear una traza o cambiar sus extremos exige poder ver ambos extremos (validación). Storage rechaza duplicados, ciclos en `includes`/`generalizes` y trazas entre Developments distintos. El ámbito se resuelve para `development`, `kb_development` y Project (sin ámbito). `extends` conserva su semántica aceptada. |

### Decisiones de implementación

* Solo mecanismos nativos de Drupal: presave/preDelete, constraints, servicios, `LockBackendInterface`, transacciones de la Database API y callbacks post-transacción. No se añadió ninguna capa de abstracción nueva.
* Los locks de captura y de trazas se liberan cuando termina la transacción raíz, no al salir del método. Así, el siguiente escritor lee datos confirmados.
* La guarda de moderación lee el estado de origen de `ContentModerationState`, guardado para la revisión cargada, y no del objeto que el llamador puede haber modificado en memoria.
* Los escritores en modo sincronización (`isSyncing()`) conservan la semántica de Drupal en la moderación de artefactos.
* La política de retención permanente de baselines, incluidas las vacías, no cambia.
* La autorización de endpoints de la Iteración 1 no cambia.
* CodeDocs solo participa mediante la enumeración de C07; no se modificó su código.

## Pruebas

Pruebas Kernel nuevas, positivas y negativas, sobre Entity API y storage reales:

| Clase | Cubre |
| --- | --- |
| `BaselineCaptureTest` | Baseline vacía distinguible, fallo inyectado y rollback, milestone no alcanzado tras fallo, reintento rechazado, borrador pendiente excluido, espera ante otro lock y alcance concurrente (contención determinista). Liberación de locks en éxito y excepción. |
| `StateInvariantTest` | Matriz de estados no declarados y movimientos directos por tipo, transiciones de moderación de artefactos y sincronización. |
| `ArtifactKeyStabilityTest` | Secuencia al crear, rechazo en storage y en validación, revisiones, revert y secuencia concurrente con dos instancias de State. |
| `DevelopmentDeletionTest`, `DevelopmentReferencesTest` | Cada tipo dependiente impide borrar, el Development vacío se borra con su estructura y la enumeración incluye los módulos integrados. |
| `TraceIntegrityTest` | Escritor sin acceso a un extremo, ámbitos por tipo, integridad para escritores directos, ciclos solo en relaciones acíclicas, duplicado y ciclo con otro escritor reteniendo el lock, y escritores no relacionados sin espera. |

Se adaptaron los fixtures de `BaselineRetentionTest` y `TraceVisibilityTest`, que escribían estados o trazas que ahora son inválidos por diseño. Sus aserciones no cambian.

La contención determinista usa `ScriptedLockBackend`. Ese backend retiene un lock como si fuera otra petición y, cuando el escritor espera, ejecuta la escritura de esa otra petición con su propia conexión y transacción. **No equivale a dos transacciones concurrentes reales.** Ninguna prueba usa `fork()` sobre conexiones abiertas.

### Resultados

| Ejecución | Resultado |
| --- | --- |
| Suite Kernel completa, PHP 8.4.26 / PostgreSQL, base desechable | 203 pruebas, 4.995 aserciones, 0 errores, 0 fallos, 14 avisos de deprecación |
| Functional `TraceAccessHttpTest` (Iteración 1), servidor en loopback | 1 prueba, 38 aserciones, 0 errores, 0 fallos, 17 avisos de deprecación |
| `scripts/check-install.sh`, instalación limpia del recipe | 0 comprobaciones fallidas |

Las deprecaciones vienen de Core, Symfony y módulos contrib. Cada ejecución usó una base `studio_it2_*` nueva, eliminada al terminar. No se usó la base del sitio.

## Garantías de concurrencia y limitaciones

| | Mecanismo | Prueba | Tipo de verificación | Riesgo pendiente (#23) |
| --- | --- | --- | --- | --- |
| C04 | Lock de transición por registro, relectura del estado guardado, lock de baseline por Development y una sola transacción para milestone, cabecera e items | `BaselineCaptureTest` | Contención determinista y fallos inyectados | La selección usa varias consultas `READ COMMITTED`: una publicación confirmada entre ellas puede producir un conjunto de distintos momentos. Los locks tomados fuera de la transacción caducan a los 30 s, y una captura o transición más larga podría solaparse. |
| C07 | Comprobación de dependientes en `preDelete`, dentro de la transacción de borrado | `DevelopmentDeletionTest` | Secuencial | TOCTOU: un dependiente creado o reasignado entre la comprobación y el commit puede quedar huérfano. |
| C08 | Lock por terna (tipo, origen, destino) y lock por relación acíclica, tomados dentro de la transacción de guardado y liberados al terminarla. La validación se ejecuta después de obtenerlos. | `TraceIntegrityTest` | Contención determinista | No hay restricción UNIQUE en base de datos. No se ejecutaron transacciones independientes reales. SQL directo queda fuera de las garantías de Entity API. |

Sobre C08: el lock se registra en `semaphore` dentro de la transacción del escritor, así que un segundo escritor queda bloqueado por el índice único hasta que el primero confirma o revierte. Es un razonamiento sobre el código y PostgreSQL, **no una verificación ejecutada** con dos transacciones independientes.

Requieren comprobaciones adicionales, según #23:
* el criterio de C04 «no mezclar revisiones» entre una captura y publicaciones concurrentes;
* el criterio de C07 frente a inserciones concurrentes;
* el criterio de C08 «concurrent duplicate/cycle tests» con transacciones independientes.

## Compatibilidad

* **DDL:** `divengine_process_update_11001()` instala el campo base `item_count`. Las baselines existentes quedan con recuento vacío, sin inferirlo de sus items. Tras un despliegue aprobado hay que ejecutar las actualizaciones de base de datos y reconstruir la caché.
* **Escritores que cambiaban estados con `save()`** directo deben aplicar transiciones (`LifecycleManager::apply()`), y los artefactos deben recorrer su workflow. La edición de contenido publicado crea un borrador pendiente.
* **Códigos de artefacto:** un cambio manual del código ya no es posible, ni siquiera para administradores.
* **Borrado de Developments:** se rechaza mientras tengan registros. Antes, algunos dependientes quedaban huérfanos.
* **Trazas:** un escritor necesita acceso de vista a ambos extremos, y las trazas cruzadas entre Developments o con ciclos se rechazan también por API.
* **Ciclos de vida de QA:** no forman parte de `StateGuard`, porque sus ejecuciones no declaran transiciones.

## Reproducción

Desde una copia aislada con `SIMPLETEST_DB` apuntando a una base desechable:

```sh
vendor/bin/phpunit -c phpunit.xml.dist --no-progress --do-not-cache-result --testsuite kernel
SIMPLETEST_BASE_URL=http://127.0.0.1:18093 BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browser-output \
  vendor/bin/phpunit -c phpunit.xml.dist \
  web/modules/custom/divengine_traceability/tests/src/Functional/TraceAccessHttpTest.php
STUDIO_TEST_DB=pgsql://user:pass@127.0.0.1:5432/disposable scripts/check-install.sh
```
