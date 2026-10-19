# Plan de ejecución propuesto

Este plan divide el backlog en cambios revisables. Las iteraciones son orden de trabajo y puertas de aceptación, no fechas prometidas. Este commit entrega análisis y evidencia; no implementa las acciones.

## Iteración 1: privacidad y conservación histórica

Orden: C01 acceso de trazas, C02 revisión nueva/inmutabilidad, C03 borrado protegido. Cada corrección con su regresión negativa y positiva; convertir las sondas pertinentes en tests de garantía esperada, sin copiar sus expectativas defectuosas.

Salida: outsider no conoce proyecto privado por su traza; editar una traza no altera una baseline; storage no elimina revisiones protegidas. Comparar baseline antes/después de cada operación. No migrar Development ni agregar infraestructura. Evaluar si ya hay baselines dañadas mediante un diagnóstico de solo lectura; recuperar únicamente desde backups/evidencia auténtica cuando exista, nunca reconstruir contenido inventado.

## Iteración 2: captura, estados y referencias

1. C04 transacción/coordinación de captura y resultado del milestone, con fallo inyectado.
2. C08 contratos de extremo/autoría y ámbito, incluyendo artículos; unicidad concurrente donde sea necesaria.
3. C05 guard de estados operacionales y política de cambios moderados; C06 códigos estables.
4. C07 prevención de huérfanos al borrar Development, comprobada por cada integración.

Salida: invariantes iguales en UI, servicio y operaciones de persistencia soportadas; el default aprobado permanece efectivo hasta el cambio autorizado; capturas fallidas no dejan estado engañoso. Registrar límites de llamadores confiables y operaciones administrativas. Revisar propuesta de historial; las decisiones arquitectónicas que alteren el contrato se aprueban explícitamente antes de aplicarlas.

## Iteración 3: instalación y validación de lo implementado

Comenzar con C16 requisitos efectivos y C11 aislamiento de instalación/upgrade. Agregar C15 preflight y separar avisos propios de upstream. Ejecutar C09a–d como trabajos independientes, priorizando QA y permisos/archivos privados. Ejecutar C10 por tres integraciones acotadas: importador CodeDocs real, lectura MCP y edición de diagrama/embed en navegador.

Salida: recipe base y MCP opcional comprobadas en sitio efímero; upgrades de los módulos cambiados; prueba negativa de aislamiento; cuatro suites JS ejecutadas y reportadas; Kernel, SQLite y PostgreSQL distinguidos. Se pueden distribuir los trabajos entre commits sin exigir un único PR enorme.

No reutilizar la base del sitio para ganar velocidad. Los fixtures neutros deben vivir en bases/repositorios temporales identificados; cleanup solo elimina lo creado por la ejecución. Una prueba no ejecutada permanece pendiente aunque otra suite pase.

## Iteración 4: contratos y documentación concordantes

C13: revisar las propuestas de contexto, baselines y CodeDocs; resolver la relación ADR-0002/0007/0008 y documentar APIs estables de extensión. Mantener Project y Diagram según sus ADR salvo decisión contraria expresa. Extraer acoplamientos concretos solo si hay beneficio demostrable.

C12: actualizar wiki por propósito, roadmap interno y website con matriz de madurez y alcance. Corregir cuanto antes afirmaciones incorrectas ya identificadas, sin esperar para moderar una promesa falsa; actualizar garantías verificadas al cerrar los fixes. Regenerar mapas/capturas de recorridos cambiados y HTML desde fuentes, conservar evidencia y revisar enlaces. No aprobar un ADR mediante una edición de manual.

Salida: código, tests, ADR, wiki y website describen las mismas prestaciones y límites; cualquier capacidad aún no validada lleva esa categoría. Commits por repositorio y referencias cruzadas a la evidencia correspondiente.

## Iteración 5: rendimiento y ampliaciones condicionadas

C14 después de estabilizar integridad: benchmark representativo, presupuesto de consultas/memoria y cambios puntuales. No usar resultados de un dataset pequeño como garantía para miles de tablas o millones de símbolos.

C17 solo entra si se identifica necesidad de baseline integral de release y se acepta un ADR. Extraer un contexto genérico a otro módulo tampoco es una tarea automática de esta iteración: requiere consumidor, comparación y migración aprobados.

## Seguimiento de cierre

Para cada Cxx registrar commit/PR, prueba de aceptación, resultado y cambios de documentación. Cerrar una acción por evidencia del comportamiento esperado, no por existencia del código. Una excepción o riesgo aceptado debe quedar explícito con responsable y alcance, sin marcar como corregido un escenario que sigue reproduciéndose.

La condición de consolidación es completar P0 y P1 con sus regresiones, disponer de instalación/upgrade seguros y alinear las afirmaciones públicas. No exige terminar el roadmap ni reescribir la suite.
