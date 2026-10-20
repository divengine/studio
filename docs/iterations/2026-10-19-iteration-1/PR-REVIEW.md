# Revisión adicional del PR de Iteración 1

Fecha: 2026-10-19. [PR #21](https://github.com/divengine/studio/pull/21), base `main` en `ad535bc`, implementación revisada `735df0f`. Este complemento registra rendimiento y consecuencias operativas de C01–C03. No modifica código funcional, ADR ni alcance; no implementa C14 ni autoriza un despliegue.

## TraceVisibility: coste observado

`TraceVisibility::alterQuery()` descubre tipos e IDs distintos en toda la tabla física de trazas por cada alias y extremo. Esas consultas no incorporan los filtros, rango o límite de la consulta original. Carga los extremos en lotes de 100 y llama al handler real de acceso; agrega condiciones por tipo e IDs permitidos antes de ejecutar la consulta final. Esto preserva conteos y páginas correctos, pero una página pequeña no acota el trabajo de acceso.

Se ejecutó una [sonda reproducible](evidence/TraceVisibilityReviewProbeTest.php) sobre PHP 8.4.26 / Drupal 11.3.12 / PostgreSQL 16.15, en una copia física temporal y una base nueva eliminada al terminar. Dos subclases de instrumentación cuentan entradas a `access()` y aliases, delegando sin cambiar la política distribuida. El fixture sintético contiene N artefactos visibles del mismo Development y N trazas; la consulta filtra `source_entity_id = 1` y siempre devuelve una fila o cuenta 1. La creación SQL del fixture queda fuera del tiempo medido y solo ocurre en esa base desechable.

Entity Query incorpora **dos aliases de `trace_link`** (`base_table` y `trace_link`). Cada uno repite el descubrimiento y el acceso a los extremos. Hay N IDs distintos de origen y dos de destino; se observan `2 × (N + 2)` entradas al handler por consulta.

| N artefactos / trazas | Modo | Tiempo ms | SQL totales | Llamadas access() | Parámetros SQL final | Bytes SQL final |
| ---: | --- | ---: | ---: | ---: | ---: | ---: |
| 100 | Página fría | 72,105 | 11 | 204 | 209 | 7.033 |
| 100 | Conteo frío | 40,343 | 10 | 204 | 209 | 7.012 |
| 100 | Página caliente | 16,708 | 9 | 204 | 209 | 7.033 |
| 1.000 | Página fría | 429,445 | 19 | 2.004 | 2.027 | 65.934 |
| 1.000 | Conteo frío | 392,225 | 21 | 2.004 | 2.027 | 65.913 |
| 1.000 | Página caliente | 149,387 | 9 | 2.004 | 2.027 | 65.934 |
| 3.000 | Página fría | 1.651,161 | 41 | 6.004 | 6.067 | 198.574 |
| 3.000 | Conteo frío | 1.691,561 | 41 | 6.004 | 6.067 | 198.553 |
| 3.000 | Página caliente | 910,912 | 9 | 6.004 | 6.067 | 198.574 |

Datos completos, resultados JUnit resumidos y limitaciones en [review-results.json](evidence/review-results.json). Resultado final: **2 sondas / 84 aserciones, salida 0, sin errores/fallos/omitidas; 7 deprecaciones** de Core/Symfony/configuración de Views. Son adicionales a la evidencia previa de 153 casos; no representan una repetición de esa suite.

Interpretación y límites:

- En este fixture cada alias hace cuatro consultas de descubrimiento (tipos e IDs de origen/destino): ocho por consulta final. Las cargas y comprobaciones de contexto añaden SQL según storage y estado de caché. No se promete un número universal para tipos con campos, referencias o handlers distintos.
- «Fría» reinicia la caché de entidades Artifact en memoria, los access handlers de Artifact/Development y el servicio de visibilidad; no vacía cachés persistentes ni PostgreSQL. «Caliente» sigue inmediatamente al conteo. Las llamadas cuentan también hits de la caché de acceso de Core; no equivalen a 6.004 cálculos completos de política.
- Aun caliente, hay nueve SQL y se recorre el conjunto de extremos. El tamaño final del SQL y sus parámetros crece con los IDs visibles de cada alias, incluso devolviendo una sola fila. Los lotes de 100 acotan cada `loadMultiple()`, no la lista total de condiciones. Un límite del driver/servidor a mayor escala es un riesgo pendiente de medir, no un fallo provocado aquí.
- Los tags acumulados crecen de 103 a 1.003 y 3.003. `remember()` fusiona metadatos repetidamente; Core combina/deduplica arrays de tags. Esto sugiere un coste acumulado potencialmente cuadrático: es una inferencia del código, sin perfil que atribuya cuánto del tiempo medido corresponde a esa fusión.
- No hay allowlist persistente de permisos. Los metadatos incluyen usuario/permisos, entidades y listas; los cambios de contenido/membresía invalidan conservadoramente `trace_link_list`. La regresión HTTP previa comprueba revocación tras calentar respuestas. Esa invalidación amplia puede reducir hits en sitios con muchas escrituras; esta sonda no mide esa tasa ni concurrencia.
- Una muestra por modo/tamaño, un contexto y un tipo de extremo visible. No establece capacidad máxima, percentiles, latencia del sitio real ni un SLO. La corrección de privacidad con extremos privados se acredita mediante las pruebas de C01, no mediante este fixture de escala.

La primera ejecución de la sonda falló porque esperaba un alias y encontró dos: 204 frente a 102 llamadas a N=100. Se corrigió únicamente la expectativa e instrumentación de la sonda y se repitió en otra base nueva. Ese intento no se cuenta como superado; el producto no cambió.

### Propuesta de issue independiente, sin implementar

**Título propuesto:** `TraceVisibility: bound full-graph access work for selective paginated queries`.

Problema reproducible: consultar una única traza de un grafo de 3.000 ejecuta 6.004 entradas de acceso y genera unos 198 KB de SQL, con 1,65 s en la muestra fría y 0,91 s caliente. El filtro selectivo y la página no reducen el recorrido; los aliases repiten trabajo.

Evidencia y reproducción: la sonda y resultados enlazados arriba. Coordinar el seguimiento con [C14 / #17](https://github.com/divengine/studio/issues/17), que ya contempla benchmarking general de grafos/snapshots. No se abre un duplicado automáticamente ni se implementa esa iteración.

Criterios propuestos: acordar cargas y presupuesto de consultas/latencia; perfilar aliases, cargas, checks y fusión de metadatos; cubrir extremos mixtos/privados, conteos, paginación, cuenta explícita y revocación de membresía; comparar mediciones antes/después. Cualquier optimización deberá preservar el acceso real y la cacheabilidad. No se elige aquí una estrategia alternativa ni un ACL SQL nuevo.

## Política de retención y ADR-0007

[ADR-0007, revisiones y baselines](../../architecture/adr-0007-unified-process.md#revisions-and-baselines) exige mantener identidad y revisión capturada, no modificar materialmente en sitio y no borrar revisiones que participaron en una baseline. C02 y C03 hacen cumplir ese contrato en Entity API/storage. C02 conserva el historial de todas las ediciones de trazas; C03 protege las revisiones referenciadas y los registros que las contienen.

Prohibir borrar una cabecera evita que su `postDelete()` elimine los items y libere las protecciones. La prohibición de **todas** las baselines, incluso vacías, es una política conservadora más estricta que el texto literal del ADR. Requiere aceptación explícita del revisor respecto de retención indefinida y baselines vacías. El ADR no se ha modificado y no se ha añadido una excepción administrativa.

| Operación | Consecuencia y evidencia | Límite / decisión pendiente |
| --- | --- | --- |
| Mantenimiento | Revisiones capturadas y registros protegidos no se borran; revisiones no protegidas no default y registros no capturados sí, como verifica `BaselineRetentionTest`. Cada save de traza agrega revisión. | Crecimiento indefinido del historial capturado y de baselines vacías. Planificar espacio/backup; no hay política de expiración autorizada. |
| Purge | El storage rechaza `purgeFieldData()` y `finalizePurge()` con valores capturados en tablas dedicadas de revisiones; los casos sin valores protegidos sí pasan. Core invoca esos métodos desde `field_purge_batch()`/`field_purge_field_storage()`. | Un purge periódico puede fallar/reintentarse y retener tablas/espacio. Se conservan valores, no definiciones de configuración: borrar configuración puede impedir su interpretación/renderizado. No se probó un ciclo completo de cron ni toda forma de cambio de esquema. |
| Desinstalación | La sonda crea una baseline vacía y llama al `ContentUninstallValidator` nativo: Process queda bloqueado por contenido Baseline. La preparación de desinstalación de Core usa `storage->delete()`, que no evita la retención. Artifact/Traceability tampoco pueden vaciar entidades protegidas mediante Entity API. | No se ejecutó desinstalación. La eliminación ordinaria del módulo necesita eliminar su contenido, operación incompatible con la retención indefinida. No hay bypass por administrador o syncing. Una alternativa futura exige decisión arquitectónica y conservación verificable. |
| Actualizaciones | Esta iteración no tiene DDL/update hook/backfill ni altera registros existentes. Requiere rebuild de handlers/servicios al desplegarse con aprobación. | Un update futuro que borre o reescriba revisiones protegidas por Entity API fallará; DDL/cambios de esquema no tienen un guard universal aquí y deben revisarse separadamente. No se certifica toda actualización futura. |
| Migraciones | El destino Entity de Core elimina entidades en rollback y ContentEntityBase guarda con syncing; los guards de storage siguen aplicándose. El test de syncing verifica el rechazo de una sobrescritura protegida. | Preservar IDs/UUIDs, IDs de revisión e items; editar mediante nuevas revisiones. No se ejecutó import/rollback completo ni se diseñó un formato nuevo de baseline. Migrar fuera de este esquema requiere conservar y validar el contrato histórico. |
| Recuperación | La prevención y el diagnóstico read-only detectan referencias ausentes/inconsistentes; no reconstruyen contenido perdido. El diagnóstico previo/posterior del sitio registra 1 baseline, 3 items y 0 incidencias detectables. | Para recuperación hacen falta backups coherentes de contenido, revisiones, items y configuración correspondiente. Sin hashes/copias independientes no se demuestra ausencia de sobrescritura antigua. No se ejecutó restauración ni reparación. |

Las comprobaciones preceden a los borrados de revisiones y a todo el lote de registros; la regresión de cascada comprueba rollback. La carrera entre captura y eliminación sigue siendo C04 y no se presenta como resuelta. SQL directo o eliminación manual de tablas pueden destruir la historia fuera de los guards de Entity API; no se proponen como procedimiento operativo.

Contratos de Core revisados en la versión bloqueada 11.3.12: `ContentUninstallValidator::validate()`, `PrepareModulesEntityUninstallForm::deleteContentEntities()`, `field.purge.inc`, destinos migrate `Entity::rollback()` y `EntityContentBase::save()`, `CacheableMetadata::merge()` y `Cache::mergeTags()`. Las consecuencias de rutas no ejecutadas se derivan de esa revisión estática y se distinguen de las pruebas positivas/negativas existentes.

## Reproducción de las sondas adicionales

Desde una copia física aislada del checkout con dependencias de desarrollo instaladas según `composer.lock`, PHP/PostgreSQL compatibles y una **base nueva desechable**. Configurar `SIMPLETEST_DB` solo para esa base; no usar el DSN del sitio desplegado. El runner que se usó creó y eliminó una base nueva por ejecución; el comando PHPUnit equivalente no crea ni elimina la base por sí mismo.

```sh
STUDIO_REVIEW_EVIDENCE=/tmp/studio-pr-review-metrics.jsonl \
  vendor/bin/phpunit -c phpunit.xml.dist --no-progress --do-not-cache-result \
  --log-junit /tmp/studio-pr-review.xml --filter testReview \
  docs/iterations/2026-10-19-iteration-1/evidence/TraceVisibilityReviewProbeTest.php
```

Usar un archivo de métricas nuevo y un directorio escribible. El filtro es necesario porque la sonda hereda el fixture `DevelopmentTest`; sin él se ejecutan también sus pruebas heredadas. La sonda está fuera de la selección Kernel predeterminada y no cambia los totales anteriores. El hash SHA-256 de la fuente ejecutada está registrado en `review-results.json`.

El docroot desplegado continúa limpio en `b6607ae`; no se aplicó la rama, no se reiniciaron servicios y quedaron cero bases temporales `studio_it1_%` tras las sondas. Ninguna operación destructiva se ejecutó contra el sitio. Las comprobaciones GitHub del PR no tienen checks publicados al momento de esta revisión; las ejecuciones aquí descritas son evidencia manual reproducible, no CI verde.

## Disposición para revisión

No se identificó una corrección funcional indispensable para preparar el PR. Se publica esta evidencia en un commit separado de documentación/sonda. Permanecen pendientes la aceptación de C01–C03, el seguimiento de escala propuesto y la aceptación de las consecuencias de retención. Las tres issues continúan abiertas. No se fusiona `main`, despliega ni inicia la Iteración 2.
