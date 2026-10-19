# Propuesta de ADR: contrato de baselines e historial

Estado: **Proposed — pendiente de revisión**. Complementaría ADR-0007; no lo reemplaza ni se aplica mediante este informe.

## Problema

La baseline guarda IDs exactos, pero el contenido de una revisión de traza puede cambiar y una revisión protegida puede borrarse por storage (F02/F03). La captura y su milestone no son una operación atómica (F04). El contrato de «reproducible» necesita especificar contenido, configuración y vías de escritura soportadas.

## Decisión propuesta

Mantener una baseline de **artefactos efectivos del Development y sus trazas internas**, sin transformarla en un snapshot integral. Documentar selección, estado de publicación de trazas y tratamiento explícito del conjunto vacío. Preservar readonly del conjunto tras finalización y garantizar revisiones capturadas inmutables y retenidas en todas las escrituras soportadas.

La captura debe completar selección coherente, cabecera e items con resultado indivisible; si se origina al alcanzar un milestone, el milestone y la captura deben tener un resultado transaccional acordado. Transacción y coordinación deben diseñarse juntas: una transacción bajo aislamiento por defecto no implica una fotografía consistente de lecturas sucesivas.

Para una revisión protegida, prohibir modificación y borrado en storage mediante la extensión Drupal adecuada. Crear una revisión nueva para cambios de trazas. Reverts deben producir revisión nueva. Permisos de UI/API conservan su función y no sustituyen las restricciones históricas. Definir explícitamente operaciones administrativas excepcionales y retención antes de habilitarlas.

## Alternativas y alcance de «exacto»

1. **Revisiones retenidas:** usa infraestructura Drupal y conserva identidad lógica. Recomendación para consolidar; exige guard de actualización/eliminación y fijar qué metadatos interpretativos se preservan.
2. **Copias serializadas en cada baseline:** independencia de revisiones, pero duplica storage, formatos y migraciones; no se justifica todavía como reemplazo.
3. **Baseline integral:** incluir versiones de config, diagramas, QA, commits, schemas y CodeDocs como manifiesto. Es ampliación P3 y requiere caso de uso, contrato de versiones y restauración; no requisito implícito del diseño actual.

Recuperar título/campos/rationale históricos no equivale a reproducir exactamente todas las etiquetas configurables o el HTML de aquel día. La aceptación debe elegir el nivel de fidelidad. Si se necesita interpretar tipos de relación eliminados o modificados, guardar al menos los metadatos de interpretación necesarios o retener versiones de configuración; no prometerlo con solo revision IDs.

## Verificación necesaria

Regresiones de F02/F03; editar y deprecar después de capturar; draft pendiente excluido de selección; extremos que no pertenecen al conjunto; fallo antes/después del insert; dos capturas simultáneas; rollback de milestone; vacío válido distinguible; cambios de config; permisos de lectura de revisiones históricas. Validar y explicar el tratamiento de baselines existentes dañadas, sin inventar contenido perdido ni borrar evidencia.
