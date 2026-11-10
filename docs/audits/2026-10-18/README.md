# Auditoría de consolidación de Divengine Studio

> **Archived record — not maintained.** Consolidation audit of 2026-10-18 at commit `b6607ae`, written in Spanish. Every action it proposes became a GitHub issue (C01–C17, #1–#20). The P0 and P1 defects it describes were fixed before any release; the remaining follow-ups are open issues #11 and #15–#18, and the concurrency conditions of #23. Its commands and the state it reports are those of that date. For current behaviour see the module READMEs, the [ADRs](../../architecture/) and [docs/development.md](../../development.md); the [docs index](../../README.md) explains how historical documents are kept.

Fecha: 18 de octubre de 2026. Estado: análisis terminado; propuestas pendientes de revisión.

Studio tiene una base apropiada para continuar: entidades y configuración de Drupal, Content Moderation, permisos, plugins y recipes. No se justifica una reescritura. La prioridad es cerrar garantías de autorización e historial que hoy dependen de la vía de acceso utilizada.

Se confirmaron tres defectos P0: una traza permite conocer el título de un proyecto privado; una revisión de traza incluida en una baseline puede sobrescribirse; una revisión de artefacto protegida puede eliminarse mediante storage. La captura de baselines tampoco tiene una transacción que abarque cabecera, elementos y transición del milestone. Los resultados y sus límites están separados en el informe de hallazgos.

## Entregables

1. [Arquitectura actual y mapa de dependencias](01-arquitectura-actual.md).
2. [Matriz de coherencia: código, ADR, wiki y website](02-coherencia.md).
3. [Defectos, riesgos y evidencias](03-hallazgos.md).
4. [Inventario y matriz de pruebas](04-pruebas.md).
5. Propuestas de ADR, **sin adoptar**: [contexto de ingeniería](propuestas/contexto-ingenieria.md), [contrato de baselines e historial](propuestas/baselines-e-historial.md), [contrato de CodeDocs](propuestas/codedocs.md).
6. [Backlog priorizado, con criterios verificables](06-backlog.md).
7. [Plan de ejecución por iteraciones](07-iteraciones.md).

## Base y alcance

| Componente | Revisión analizada | Ubicación |
| --- | --- | --- |
| Studio | `b6607ae81efa5cead00f4c26342bc1be4ebf6f8f` | Copia local y checkout de la VM de desarrollo |
| Wiki | `c14a54b` | Copia local del wiki |
| Website | `548ee1e` | Copia local de divengine.com, fuentes en `_build/pages` |

Se revisaron los 18 módulos personalizados, sus entidades, handlers, servicios, configuración, integraciones y pruebas; los ADR disponibles; los manuales pertinentes y las afirmaciones públicas del website. Es una auditoría de código y contratos, complementada con ejecución aislada, no una certificación de seguridad ni una validación completa de todos los recorridos de usuario. Las observaciones de rendimiento sin medición están identificadas como riesgos.

El mantenedor autorizó publicar los entregables y pruebas. Se conserva el alcance de análisis: este cambio agrega el informe y evidencia, sin cambiar módulos, recipes, wiki, website ni decisiones aceptadas. No se reiniciaron servicios ni se desplegaron correcciones funcionales durante esta auditoría.

## Evidencia ejecutada

| Ejecución | Resultado |
| --- | --- |
| Suite Kernel existente, PHP 8.4.26 / PostgreSQL, VM | 143 pruebas, 2.386 aserciones; 0 errores, 0 fallos, 0 omitidas; 12 categorías de deprecación |
| Sondas diagnósticas, bases PostgreSQL desechables | 7 pruebas, 224 aserciones; reprodujeron comportamientos inseguros; no son pruebas de correcciones |
| Runner CodeDocs local, PHP 8.3.7 / SQLite | 41 comprobaciones superadas |
| Mismo runner CodeDocs en la VM | Bloqueado: falta `Pdo\Sqlite`; salida 255 |
| Cuatro suites JavaScript | No ejecutadas: faltan `node_modules` |
| Instalación limpia, actualizaciones, Functional y navegador | No ejecutados; ver limitaciones y escenarios pendientes |

Las bases creadas para Kernel y sondas se eliminaron al finalizar. No se usó la base del sitio para pruebas destructivas. [Evidencia](evidence/README.md) conserva resultados, inventario y fuentes de las sondas. Una suite verde no demuestra los invariantes ausentes: las sondas se diseñaron precisamente para mostrar esa diferencia.
