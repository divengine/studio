# Matriz de coherencia y presentación del producto

Comparación a las revisiones indicadas en el índice. Las referencias a wiki y website son archivos de sus repositorios, no cambios publicados. «Verificado» se limita al comportamiento y entorno realmente ejecutados.

## Código, decisiones y documentación

| Tema | Código / prueba | ADR | Wiki / website | Dictamen y acción |
| --- | --- | --- | --- | --- |
| Entidades de ingeniería | `artifact`, bundles y `trace_link`; pruebas Kernel | ADR-0001/0002/0007 | Wiki `Architecture.md` y `Artifacts.md` coinciden en lo esencial | Conservar modelo nativo. Resolver estado **Proposed** de ADR-0002 sin aprobarlo por implicación. C13. |
| Identidad vs código | id/uuid estable; `Artifact::preSave()` permite otro código | ADR-0007 §§ Revisions and baselines exige código estable | Wiki `Artifacts.md` presenta código como referencia permanente | Inconsistencia reproducida, F06/C06. |
| Nueva revisión pendiente | `ArtifactLifecycleTest::testReviewApproveAndDeprecate()` prueba draft explícito; save directo publicado mantiene published | ADR-0007 dice que editar baselined inicia forward revision pendiente | Wiki `Unified-Process.md`, sección work products | Mantener Core; acordar comportamiento de todas las escrituras, F05/C05. |
| Historial de trazas | `TraceLink::preSave()` no fuerza revisión; sonda altera baseline | ADR-0007 exige reproducibilidad | Wiki `Traceability.md`, tabla History; `Baselines.md:38–39` promete inmutabilidad | Defecto F02/F03; C02/C03 antes de sostener garantía absoluta. |
| Alcance de baseline | `BaselineRecorder::take()` solo artefactos efectivos y trazas internas | ADR-0007 describe ese conjunto | Wiki `Baselines.md` describe artefactos y trazas | No confundir con configuración integral ni una selección solo de requisitos. Aclarar selección, vacíos y representación histórica en C12. |
| Captura compuesta | Cabecera e items sin transacción conjunta; milestone se guarda primero | ADR-0007 promete baseline al alcanzar milestone | Wiki `Baselines.md`, creación automática | Definir resultado atómico, F04/C04. Readiness sigue consultivo. |
| Extremos privados | Sonda: proyecto oculto, traza y etiqueta visibles | ADR-0002 requiere extremos visibles; ADR-0009 separa Project | Wiki `Traceability.md:98` dice que ambos extremos son visibles | Ruptura real F01/C01, no solo documentación atrasada. |
| Relación entre contextos | Constraint UP reconoce `development`, no `kb_development`; Project independiente | ADR-0007/0009 | Wiki `Unified-Process.md`; `Knowledge-Base.md` | Explicitar ámbito compartido y excepciones, F08/C08. No meter Project en Development unilateralmente. |
| Casos de prueba | `artifact:test_case` y `qa_test_case` son modelos distintos | ADR-0002 propuesto y ADR-0008 aceptado | Wiki `Artifacts.md` y `Test-Management.md` | Distinguir conocimiento referenciable del diseño ejecutable. No eliminar uno por duplicación nominal. C12/C13. |
| Momento del snapshot QA | `QaTestRun::postSave()` llama `QaSnapshot::build()` al crear | ADR-0008 snapshots congelados | Wiki `Test-Management.md:96` coincide; website `_build/pages/quality.html:30` dice al comenzar | Corregir website a creación o revisar explícitamente el comportamiento; no cambiar código para acomodar copy. C12. |
| QA «completo» | 10 tipos de contenido, roles y servicios implementados; sin pruebas propias | ADR-0008 | Website `quality.html:7`: «complete test management module» | Implementado pendiente de validación; C09/C10/C12. |
| UP «full implementation» | Configuración, workflows y readiness; quedan fallos y funciones previstas | ADR-0007 contiene prestaciones todavía no completas | Website `about.html:14` afirma implementación completa | Describir capacidades concretas y madurez, C12. |
| Overview extensible | `DevelopmentViewBuilder` construye tarjetas concretas | ADR-0007: tarjetas plugins | Wiki arquitectura habla de extensibilidad | Decidir extensión necesaria y reconciliar ADR, C13; no prometer manager inexistente. |
| Diagrama de módulos | 18 módulos declarados | ADR-0011/0012/0013 agregan áreas | Wiki `Architecture.md`, PlantUML/imagen: omite Thread, Articles, Source, Database, CodeDocs; tabla incluye varios pero no CodeDocs; dibuja Knowledge → Artifact aunque depende de Core | Regenerar mapa desde inventario y actualizar fuente + SVG conjuntamente, C12. |
| Navegación y capturas | Rutas de Knowledge, Source, Database, CodeDocs existen | ADR-0011/0012/0013 y propuesta CodeDocs | Wiki `Unified-Process.md`, tabla tabs, omite navegación agregada; captura `images/screens/development-business.png` representa ese recorrido anterior | Revisar imágenes usadas en ese flujo y capturar después de fixes. No declarar obsoletas todas las imágenes por antigüedad. C12. |
| Notaciones | Plugins BPMN/UML y sus Kernel; cuatro suites JS sin ejecutar aquí | ADR-0004/0005 | Website `knowledge.html` identifica otras notaciones como roadmap | Coherente si se conserva esa distinción. |
| Database / OWL | Facts, adapters y explorer JointJS/ELK/OWL | ADR-0013 lo acepta expresamente | Wiki `Database-Schemas.md`, website `code-and-data.html` | No sustituir por procedencia tecnológica; faltan pruebas propias y de navegador. C09/C10. |
| Source y CodeDocs | Repos/snapshots e índices; CodeDocs runner de 41 checks | ADR-0012 Source; falta decisión CodeDocs equivalente | Wiki `Code-Source.md`, `Code-Docs.md`; website `code-and-data.html` ya incluye CodeDocs | Capacidades presentes, validación de integración incompleta; propuesta de ADR CodeDocs pendiente. |
| MCP | Recipe autenticada, plugins de lectura; requiere setup posterior y permisos del contexto | ADRs Source/Database; configuración real | Wiki `Connecting-AI-Assistants.md`; website index/code-and-data | Lectura implementada, no verificación integral ni autorización independiente de los handlers. C01/C10. |
| Requisitos PostgreSQL | Core `pgsql/Install/Tasks.php:26`: mínimo 16 | Drupal Core efectivo en checkout | Wiki `Installation.md:22` y FAQ del website `_build/pages/index.html:1` dicen 14+ | Requisito incorrecto, F15/C16. |
| Roadmap interno | QA/Project/MCP implementados; no se encontró generador UML determinista equivalente al descrito | ADR-0007 prevé integraciones separadas | Studio `docs/roadmap.md:57–87` marca fase 4 terminada y dice que QA/Project/AI quedan pendientes | Separar entregado, pendiente y concepto de asistencia; no anunciar generación UML por un check de fase. C12. |

## Clasificación de madurez propuesta

| Capacidad | Categoría que respalda esta auditoría | Límite de la evidencia |
| --- | --- | --- |
| Artefactos: bundles, draft, review y revisión pendiente explícita | Implementada y verificada para estos comportamientos | No cubre todas las escrituras ni estabilidad del código. |
| Trazas: compatibilidad, no self-link y duplicados en validación | Implementada y verificada a nivel Kernel | Seguridad de extremos e historial tienen defectos P0; concurrencia no validada. |
| Modeladores BPMN/UML, persistencia, previews y embeds | Implementada y verificada para componentes Kernel | Edición completa en navegador y suites JS no ejecutadas. |
| Tailoring, fases, milestones y readiness de Process | Implementada y verificada parcialmente en Kernel | No equivale a UP completo; transiciones directas y captura requieren consolidación. |
| Baselines históricas reproducibles | Implementada, garantía pendiente de consolidación | Fallos reproducidos de modificación/borrado; no publicitar inmutabilidad absoluta. |
| QA, evidencias privadas y verificación | Implementada pero pendiente de validación específica | Inspección de código; sin pruebas propias ejecutadas. |
| Project y Timesheet | Implementadas pero pendientes de validación específica | No se ejecutaron board, jerarquía, temporizador ni grid. |
| Artículos, comentarios y adjuntos | Implementados pero pendientes de validación específica | Node grants presentes; faltan pruebas de referencias, herencia y archivos privados. |
| Source | Implementada pero pendiente de validación específica | Faltan pruebas de Git, raíz permitida, pin y acceso MCP. |
| Database | Implementada pero pendiente de validación específica | Fixtures y código no prueban importación, aislamiento, diff y explorer. |
| CodeDocs: normalización y consultas del runner | Implementada y verificada en runner SQLite local | Importador Drupal/HTTP/MCP/PostgreSQL no cubiertos por esos 41 checks. |
| MCP autenticado de lectura | Implementado pero pendiente de validación integral | Faltan rutas/tokens y negaciones de acceso extremo a extremo. |
| Nuevos adapters/notaciones de terceros | Experimental hasta demostrar un consumidor externo | Existen contratos de extensión; no afirmar ecosistema ya validado. |
| Mermaid, DMN, ArchiMate, CMMN, IFML, Excalidraw, Pulse y generación UML determinista descrita en roadmap | Planificadas en los documentos pertinentes | No equiparar editor PlantUML existente a generación desde el modelo de ingeniería. |

No se propone etiquetar automáticamente todo lo que carece de pruebas como experimental: la clasificación correcta es implementado pendiente de validación. «Verificado» debe acompañarse de alcance y referencia de evidencia.

## Estructura documental propuesta

* **Arquitectura:** ADR, modelo de entidades, ámbitos/autorización, revisiones, transacciones y extensiones. Fuente técnica en Studio; wiki enlaza sin duplicar decisiones completas.
* **Desarrollo:** contratos soportados de adapters/plugins/hooks, entornos de prueba y ejemplos de integración.
* **Administración:** instalación con versiones efectivas, actualizaciones, backups, cuentas/token, Git permitido y recuperación de imports.
* **Manual de usuario:** recorridos completos por rol, requisitos → baseline → cambio → QA → verificación y acceso al código/datos.
* **Referencia funcional:** campos, estados, permisos, relaciones y alcance exacto de snapshots.
* **Website:** propuesta de valor y capacidades con madurez, enlazadas a manual y evidencia; sin garantías mayores que las verificadas.

Cada captura debe registrar página/rol, fixture neutro y comando de generación. Hay scripts de captura en `scripts/codedocs-demo/`; antes de ampliar el mecanismo, reutilizarlo y comprobar que no publica credenciales ni nombres privados. La regeneración de HTML estático debe hacerse desde `_build/pages`, conservando el generador existente.
