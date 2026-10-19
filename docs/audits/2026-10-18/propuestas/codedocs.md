# Propuesta de ADR: CodeDocs como conocimiento técnico indexado

Estado: **Proposed — pendiente de revisión**. Formaliza lo observado; no aprueba ni cambia el diseño existente.

## Contexto

Source y Database tienen ADR-0012/0013. CodeDocs agrega conjuntos y snapshots bajo Development, carga fragmentada, adaptadores y herramientas MCP sin una decisión equivalente. Su runner verifica normalización y facts sobre SQLite, no la integración completa de Drupal/PostgreSQL/HTTP.

## Decisión propuesta

Conservar `codedoc_set` y `codedoc_snapshot` como Content Entities Drupal con permisos/contexto. Mantener símbolos, unidades, parámetros, referencias y endpoints como facts dependientes del snapshot, con claves estables del contrato. No crear una Content Entity por átomo ni una capa de ORM paralela.

Los adapters son servicios etiquetados que producen el contrato normalizado; no trasladan modelos ni permisos del productor externo. Java/Javadoc y TypeScript son adapters disponibles; PHP y otros lenguajes pueden publicar el contrato genérico con scripts. «Genérico» no significa que exista un parser nativo de cada lenguaje.

El servicio de publicación debe documentar begin/append/finish, autenticación y autorización, idempotencia de chunks, estado incompleto no visible, locks, transacciones, fallo/reintento, descarte y hashes. Un snapshot finalizado debe tener semántica de inmutabilidad explícita para todos los escritores soportados, no solo ocultar campos en formularios.

MCP expone lectura mediante cuenta autenticada, permisos del conjunto y Development; no concede acceso adicional por conocer un ID de snapshot/símbolo. El vínculo a Source fija procedencia cuando existe, sin exigir que todo productor externo tenga repositorio local. Los textos extraídos se consideran entrada externa y deben pasar render/escape adecuados.

## Alternativas

* **Todo como entidades por símbolo:** más hooks/grants individuales, coste excesivo y complejidad de revisiones masivas; no hay necesidad de editar símbolos como contenido independiente.
* **Motor externo obligatorio:** facilita búsqueda especializada, pero agrega infraestructura y sincronización; no necesario para consolidar lo actual.
* **Contrato normalizado con adapters y facts actuales:** menor cambio, alineado con Database; recomendación pendiente de revisión, con límites de volumen medidos en lugar de prometidos.

## Criterios para aceptación

Pruebas PostgreSQL del importador real, permisos negativos de publicación/lectura, aislamiento entre Developments, cargas fragmentadas incompletas, reintentos y carreras, referencias sin resolver, diff entre snapshots, procedencia y acceso MCP. Documentar compatibilidad/versionado del contrato y un procedimiento de recuperación de uploads fallidos. Conservar el runner SQLite como prueba rápida de componentes, sin presentarlo como validación integral.
