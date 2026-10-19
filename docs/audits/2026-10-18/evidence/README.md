# Evidencia mínima reproducible

* [inventory.json](inventory.json): inventario derivado de `*.info.yml`, atributos de entidades, `*.services.yml` y archivos de tests en el commit analizado. El indicador de grafo acíclico se calculó sobre dependencias personalizadas declaradas, no sobre toda llamada PHP dinámica.
* [results.json](results.json): entornos, resultados y ejecuciones no realizadas.
* [junit-summary.json](junit-summary.json): métricas y 143 casos del JUnit de la suite existente, sin rutas absolutas del checkout. No incluye las sondas adicionales.
* [probe-evidence.jsonl](probe-evidence.jsonl): cinco sondas de invariantes.
* [trace-evidence.jsonl](trace-evidence.jsonl): dos sondas de historial/acceso.
* [AuditInvariantProbeTest.php](probes/AuditInvariantProbeTest.php) y [AuditTraceProbeTest.php](probes/AuditTraceProbeTest.php): fuentes diagnósticas ejecutadas fuera de la suite normal.

Las sondas heredan el fixture de `Drupal\Tests\divengine_process\Kernel\DevelopmentTest`; se seleccionan **solo** sus métodos `testAudit*` para no repetir métodos heredados. Su propósito es reproducir defectos del commit auditado: un resultado verde indica reproducción, no seguridad del comportamiento. No agregarlas sin cambios a CI como pruebas de aceptación.

Ejemplo de invocación desde Studio, **después de crear una base PostgreSQL nueva de pruebas y configurar `SIMPLETEST_DB` exclusivamente para ella**:

```sh
export STUDIO_AUDIT_EVIDENCE=/tmp/audit-invariants.jsonl
vendor/bin/phpunit -c phpunit.xml.dist --no-progress --do-not-cache-result \
  --filter testAudit \
  docs/audits/2026-10-18/evidence/probes/AuditInvariantProbeTest.php

export STUDIO_AUDIT_EVIDENCE=/tmp/audit-traces.jsonl
vendor/bin/phpunit -c phpunit.xml.dist --no-progress --do-not-cache-result \
  --filter testAudit \
  docs/audits/2026-10-18/evidence/probes/AuditTraceProbeTest.php
```

Los archivos de salida JSONL se agregan en cada ejecución; usar nombres nuevos para conservar procedencia. No se versionan valores de DSN, tokens, configuración privada ni un script que borre una base arbitraria. El procedimiento ejecutado creó bases únicas y las eliminó en `finally`; la consulta posterior de `pg_database` no encontró ninguna `studio_audit_%` remanente. El checkout remoto quedó sin cambios.

La suite existente produjo salida 0 con 12 categorías de deprecación; las sondas también emitieron deprecaciones. Ver [matriz de pruebas](../04-pruebas.md) para límites, entorno local SQLite y pruebas no ejecutadas. La evidencia contiene datos sintéticos, no contenido de usuarios del sitio.
