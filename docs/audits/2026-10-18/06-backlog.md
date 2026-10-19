# Backlog de consolidación

Este backlog es una propuesta ejecutable, no autorización para cambiar arquitectura ni una lista de correcciones ya aplicadas. Cada acción remite al [hallazgo con evidencia exacta](03-hallazgos.md). P0 = exposición o pérdida histórica crítica; P1 = consolidación necesaria; P2 = mantenibilidad/documentación; P3 = ampliación condicionada a una necesidad real.

## C01 · P0 · Acceso a trazas y referencias privadas

**Problema/evidencia:** F01, `TraceLinkAccessControlHandler::checkAccess()`, `TraceLink::label()`, `DevelopmentVisibility::alterTraceQuery()`, `TracePanel::build()` y sonda de Project privado. **Impacto:** metadatos privados accesibles desde un vínculo visible. **Módulos:** Traceability, Process, Project y consumidores QA/Articles/MCP.

**Solución:** exigir acceso a extremos para ver la traza, filtrar queries y consumidores, propagar metadatos de caché. Cubrir entidades con políticas distintas al campo `development`. **Alternativa:** mostrar extremo restringido únicamente si se acuerda que la relación misma es pública; no satisface el contrato actual de ambos extremos visibles. **Aceptación/pruebas:** outsider no obtiene traza, título, rationale ni conteos reveladores mediante entity/query/listado/API; miembro autorizado sí. Casos Project, Task, Article y QA; cambiar miembros no reutiliza caché privilegiada. Kernel y Functional con dos cuentas; lectura MCP negativa donde exponga esas referencias. **Dependencias:** ninguna; no esperar extracción de contexto. **ADR:** no para cumplir contrato existente; revisar propuesta de contexto solo si cambia la política.

## C02 · P0 · Preservar revisiones históricas de trazas

**Problema/evidencia:** F02, `TraceLink::preSave()` y `trace_revision_overwrite`. **Impacto:** la baseline apunta al ID correcto con contenido alterado. **Módulos:** Traceability y Process.

**Solución:** revisión nueva obligatoria en cambios relevantes y guard que rechace modificación en sitio de una revisión protegida; revert también crea revisión. **Alternativa:** congelar copia de la traza dentro de la baseline, más costoso y duplicado. **Aceptación/pruebas:** después de cambiar rationale/tipo/extremos por formulario y save directo, cargar la revisión capturada conserva contenido original; cargar la actual muestra cambios. Pruebas Kernel, revert y validación de traducciones si se habilitan. **Dependencias:** ninguna; coordinar con C03/C04. **ADR:** complementa ADR-0007; revisar propuesta de historial para fijar excepciones, sin bloquear la corrección básica.

## C03 · P0 · Retener revisiones protegidas en storage

**Problema/evidencia:** F03, `divengine_process_baseline_protection()` y `baseline_history`. **Impacto:** pérdida de contenido histórico; access hooks insuficientes ante escritores PHP. **Módulos:** Artifact, Traceability, Process.

**Solución:** guard Drupal de borrado de revisión/entidad en storage o extensión equivalente que cubra todas las vías soportadas; política explícita para baseline y retención. **Alternativa:** archivo de snapshots autónomo, reservado para una necesidad distinta de portabilidad. **Aceptación/pruebas:** `deleteRevision()` y delete de entidad con revisión protegida fallan sin modificar baseline/items; una revisión no protegida se puede borrar según permisos/política. Verificar APIs, purge y conservación de referencias. No habilitar bypass administrativo sin contrato y auditoría. **Dependencias:** ninguna; coordinar C02. **ADR:** propuesta de historial para retención/excepciones; no requiere reemplazar el modelo.

## C04 · P1 · Captura de baseline y milestone coherentes

**Problema/evidencia:** F04, `BaselineRecorder::take()`, `LifecycleManager::apply()`, `Milestone::takeBaseline()`. **Impacto:** cabecera incompleta o milestone alcanzado sin captura. **Módulos:** Process, almacenamiento de artefactos/trazas.

**Solución:** transacción que abarque persistencia compuesta y estrategia de consistencia/coordinación de selección; decidir qué ocurre ante dos capturas o una edición concurrente. Mantener readiness advisory. **Alternativa:** captura asíncrona con estado explícito pending/failed, complejidad que hoy no está justificada. **Aceptación/pruebas:** inyectar fallo en insert y callback revierte todo el resultado acordado; captura concurrente no mezcla versiones ni duplica por reintento; un conjunto vacío válido es distinguible de error. Probar draft pendiente, trazas seleccionadas y status según política. **Dependencias:** C02/C03 para garantizar reconstrucción posterior. **ADR:** propuesta de baselines para formalizar alcance y consistencia.

## C05 · P1 · Invariantes de estado y contenido aprobado

**Problema/evidencia:** F05 y sonda `direct_state_save`; `StatefulTrait`, `ProcessStateTransitionConstraintValidator`, `LifecycleManager`. **Impacto:** estados imposibles o contenido efectivo que omite revisión de negocio. **Módulos:** Process/Artifact e integraciones con escritores propios.

**Solución:** primero fijar contrato de escritura; proteger invariantes persistentes mínimos de estados operacionales sin ejecutar validación recursiva indiscriminada en cada presave. Después precisar y aplicar la política de edición de contenido baselined usando Content Moderation. Servicio de transición debe documentar autorización y manejar fallos/efectos de forma consistente. **Alternativa:** confiar solo en validación de cada llamador, incompatible con el requisito de invariantes de storage solicitado. **Aceptación/pruebas:** guardar estado ilegal directamente no persiste; transiciones válidas conservan guard y audit log; errores no dejan flags/efectos parciales. Matriz de Activity, CR, Development, Iteration y Milestone. En artefactos, un borrador nuevo deja default aprobado y se define el resultado de una edición directa publicada. **Dependencias:** C04 para efectos compuestos; C08 para contratos del llamador. **ADR:** no reemplazar Workflows; si cambia qué edición aprobada se permite, registrar complemento a ADR-0007.

## C06 · P1 · Estabilidad del código de artefacto

**Problema/evidencia:** F06, `Artifact::preSave()/setArtifactKey()` y constraint único. **Impacto:** referencias humanas rotas pese a id estable. **Módulos:** Artifact, Core Sequence y lectores de códigos.

**Solución:** asignación al crear, rechazo de cambio normal; definir si existe corrección administrativa auditada y alias de compatibilidad. **Alternativa:** declarar mutable el código y migrar referencias, contradice ADR-0007 y necesita revisión explícita. **Aceptación/pruebas:** save/form/API no alteran código existente; creación normal sigue secuencia; revisión y revert no renumeran; concurrencia no duplica; si se permite excepción, deja autor/motivo y política de enlaces antiguos. **Dependencias:** C08 para escritores autorizados. **ADR:** no para estabilidad actual; sí para excepción semántica.

## C07 · P1 · Dependientes impiden borrar Development

**Problema/evidencia:** F07, `DevelopmentAccessControlHandler::RECORDS`, `Development::postDelete()`. **Impacto:** registros externos huérfanos o inaccesibles. **Módulos:** Process, QA, Articles, Source, Database, CodeDocs.

**Solución:** enumeración extensible de dependientes y guard de persistencia coherente con política de retención; admitir resolución `kb_development`. **Alternativa:** cascada de borrado de conocimiento, rechazada salvo política explícita distinta. **Aceptación/pruebas:** Development con cada tipo externo por separado no puede borrarse por vías soportadas; uno sin registros puede eliminar solo su estructura. Casos de módulos deshabilitados, datos existentes y mensajes comprensibles; no introducir dependencia inversa circular. **Dependencias:** C08 define resolución, pero puede corregirse guard por módulos antes de una abstracción mayor. **ADR:** propuesta de contexto si se adopta contrato común; preservar entidades actuales.

## C08 · P1 · Contrato de extremos, autorización y validación

**Problema/evidencia:** F05/F08, `TraceLinkValidator::validateAll()`, `ProcessTraceConstraintValidator::development()/reaches()`. **Impacto:** escrituras incoherentes o referencias a ámbitos desconocidos por el validador. **Módulos:** Traceability, Process y productores de referencias.

**Solución:** documentar responsabilidades del escritor y separar integridad persistente de autorización por cuenta; resolver ámbito para los tipos registrados; garantizar duplicados donde corresponda ante concurrencia mediante restricción/lock adecuados al storage. **Alternativa:** confiar en autocomplete y UI, insuficiente para API PHP/HTTP. **Aceptación/pruebas:** crear vínculo no permite referenciar extremo privado por ID; cruzar ámbitos prohibidos falla tanto con validación como por la vía de escritura acordada; `includes/generalizes` rechazan ciclo y `extends` conserva semántica aceptada; prueba concurrente de duplicados. Validar artículos `kb_development` sin forzar Project al mismo ámbito. **Dependencias:** C01 para política de lectura; ninguna nueva entidad de contexto es obligatoria. **ADR:** propuesta de contexto si cambia el contrato extensible; no alterar relaciones aceptadas tácitamente.

## C09 · P1 · Pruebas de subsistemas implementados

**Problema/evidencia:** F09 y matriz de pruebas, diez módulos sin pruebas propias. **Impacto:** capacidades anunciadas sin regresión automática suficiente. **Alternativa común:** porcentaje de cobertura de líneas sin escenarios; no resuelve el riesgo. **ADR:** no. Dividir en cuatro trabajos entregables independientes:

| ID | Módulos / solución y aceptación | Pruebas requeridas y dependencias |
| --- | --- | --- |
| C09a | QA: snapshot Ready al crear run, plan posterior no cambia ejecución, tester ajeno no ve resultados/adjuntos, corrección deja audit trail y verifica requisito correcto | Kernel para snapshot/resultados/permisos; Functional para archivos privados. Depende de C01 en trazas. |
| C09b | Project/Timesheet: permisos en jerarquía, ausencia de ciclos, tarea pertenece al proyecto indicado, un timer activo por política y grid no duplica al reintentar | Kernel de entidades/queries y concurrencia del timer; fixtures con dos proyectos privados. Independiente de contexto UP. |
| C09c | Source/Database: Git fuera de raíces rechazado, ref fijada reproduce commit, import inválido revierte facts y snapshot, diff responde al cambio semántico esperado | Git temporal y PostgreSQL desechable; pruebas negativas API/facts. Depende del entorno seguro C11/C15. No implementar búsqueda nueva. |
| C09d | Articles/Thread/Knowledge: grants e herencia por padre, comentarios y adjuntos privados siguen host, códigos/referencias no filtran contenido | Kernel de grants y Functional de descargas; dos usuarios, árbol privado y cambios de membresía. Depende C01/C08 para referencias. |

Core y MCP reciben cobertura en consumidores y C10; no agregar tests que solo reflejen helpers triviales para completar una tabla.

## C10 · P1 · Integraciones con transporte y navegador

**Problema/evidencia:** F09; no pruebas Functional/FunctionalJS propias ni validación integral de imports/MCP. **Impacto:** rutas, autenticación y browser pueden fallar aunque Kernel pase. **Módulos:** integración de CodeDocs, MCP, Modeler y módulos del recorrido.

**Solución:** tres escenarios acotados: (a) CodeDocs begin/append/finish real PostgreSQL con chunks repetidos/incompletos y permisos negativos; (b) MCP autenticado y token revocado, acceso permitido/denegado a requisitos, código/docs/datos por contexto; (c) dos sesiones editan diagrama y artículo con embed, conflicto preserva datos y no filtra referencias. **Alternativa:** screenshots manuales sin assertions, útiles para manual pero insuficientes. **Aceptación/pruebas:** escenarios reproducibles con fixtures neutros y base desechable; transporte HTTP real y navegador solo donde se necesita. **Dependencias:** C01/C02 y C11/C15; importar docs sin depender de VM productiva. **ADR:** no para validar contratos; CodeDocs propuesto documenta límites.

## C11 · P1 · Aislar instalación y upgrades

**Problema/evidencia:** F10, `scripts/check-install.sh::reset_db()/cleanup()`. **Impacto:** borrar una base real por configuración equivocada. **Módulos:** scripts y recipes.

**Solución:** crear base y directorio únicos por ejecución, guardar identidad de lo creado y destruir únicamente eso; rechazar reutilización del DSN del sitio. Separar instalación base de MCP opcional, agregar CodeDocs y rol publisher a checks. Preparar fixture de upgrade mínimo→actual por módulos cambiados. **Alternativa:** confirmación humana antes de DROP, peor aislamiento y automatización frágil. **Aceptación/pruebas:** configuración que apunte al sitio se rechaza antes de mutación; fallo durante install limpia solo recursos propios; install+upgrade comprueban entidades, tablas, roles, workflows y imports. **Dependencias:** C16 requisitos efectivos. **ADR:** no; respeta ADR-0006.

## C12 · P2 · Alinear manual, website y roadmap con evidencia

**Problema/evidencia:** F11, matriz de coherencia con documento/sección. **Impacto:** usuarios esperan garantías falsas o siguen flujos antiguos. **Módulos/repositorios:** docs Studio, wiki y website.

**Solución:** actualizar terminología/alcance, momento del snapshot QA, mapas/tablas de módulos y tabs; separar entregado/verificado/planificado. Regenerar las capturas concretas afectadas después de estabilizar recorridos y website desde sus fuentes. **Alternativa:** quitar toda mención de capacidades sin tests, innecesario; indicar validación pendiente es más preciso. **Aceptación/pruebas:** cada afirmación importante enlaza manual/contrato y evidencia; no se anuncia historial inmutable mientras P0 siga abierto; fuentes y SVG/HTML coinciden, enlaces/capturas se revisan. Roadmap no declara generación UML entregada sin implementación. **Dependencias:** puede corregir madurez inmediatamente; garantías finales después C01–C05 y C09/C10. **ADR:** no aprobar ninguno desde copy.

## C13 · P2 · Contratos de extensión y coherencia de ADR

**Problema/evidencia:** F12, tarjetas en `DevelopmentViewBuilder`, `PageElementsTrait::findingTable`, lista Thread; ADR-0002 Proposed. **Impacto:** extensiones dependen de clases internas y decisiones ambiguas. **Módulos:** Core, Process, Thread, Database y documentación CodeDocs.

**Solución:** catálogo de APIs soportadas de tipos/plugins/adapters/hooks y responsabilidades de escritura; resolver estado ADR-0002 y revisar tres propuestas. Extraer helper Database de Core si reduce dependencia concreta; añadir extensión de tarjetas solo con consumidor identificado. **Alternativa:** framework universal de eventos/contextos, coste no demostrado. **Aceptación/pruebas:** un módulo fixture añade tipo/relación/readiness/adapter por contratos documentados sin editar consumidores; cambios puntuales conservan salida. API pública e interna identificadas sin interfaz vacía por clase. **Dependencias:** C08 contratos y revisión de propuestas. **ADR:** sí para decisiones semánticas, estados nunca aprobados implícitamente.

## C14 · P2 · Medir grafos y snapshots antes de optimizar

**Problema/evidencia:** F13, `ProcessTraceConstraintValidator::reaches()`, `CodeDocIndex`, importadores/diffs. **Impacto:** latencia/memoria y carreras no cuantificadas. **Módulos:** Process, CodeDocs, Database.

**Solución:** benchmark con grafos y snapshots progresivos, capturar consultas/memoria/latencia y reintentos concurrentes con acceso restringido; optimizar el cuello demostrado mediante consultas por lotes/índices adecuados. **Alternativa:** motor externo obligatorio o caché global, prematuros y con riesgo ACL. **Aceptación/pruebas:** dataset y presupuesto se documentan antes del cambio, perfil antes/después, resultados iguales y sin bypass de acceso. No publicar límites de volumen sin esa evidencia. **Dependencias:** C01/C08 y C09c/C10 para preservar corrección. **ADR:** solo si la medición justifica nueva infraestructura.

## C15 · P2 · Entorno reproducible y deprecaciones propias

**Problema/evidencia:** F14 y resultados de suite/runner VM. **Impacto:** regresiones ocultas por ruido y pruebas no ejecutables. **Módulos:** configuración entregada Process, pruebas y llamadas propias afectadas.

**Solución:** preflight PHP/extensiones/DB/JS, documentar perfiles runtime vs test, ejecutar runner CodeDocs en entorno compatible; convertir argumentos Views y llamadas propias deprecadas. Separar incidencias upstream con versión, sin editar vendor ni silenciar todo. **Alternativa:** tratar salida 0 como evidencia sin incidencias, incorrecto. **Aceptación/pruebas:** preflight falla con mensaje preciso antes de suite; Kernel/runner repiten resultados; disminuyen avisos propios y los upstream quedan identificados. JS se ejecuta en entorno con dependencias bloqueadas y se informa resultado real. **Dependencias:** C11 para seguridad del runner de instalación. **ADR:** no.

## C16 · P1 · Corregir requisitos de instalación

**Problema/evidencia:** F15; mínimo 16 en Core frente a wiki/FAQ 14+. **Impacto:** instalación recomendada incompatible. **Repositorios:** wiki/website y preflight Studio.

**Solución:** declarar PostgreSQL 16+ conforme al lock efectivo y verificar extensiones/versiones antes de instalar. Revisar también disponibilidad de Drush en perfil `--no-dev` frente a instrucciones, sin afirmar que `composer install` normal carece de él. **Alternativa:** degradar Drupal para conservar PostgreSQL 14, cambio de plataforma no justificado. **Aceptación/pruebas:** requisitos consistentes en wiki, FAQ, get-studio y README; preflight rechaza versión inferior antes de modificar esquema; instalación aislada con mínimo soportado. **Dependencias:** ninguna; C11 usa el resultado. **ADR:** no.

## C17 · P3 · Manifiesto integral de release, solo con caso de uso

**Problema/evidencia:** arquitectura actual de baseline excluye configuración y snapshots externos; eso es alcance, no defecto. **Impacto potencial:** una futura auditoría de release puede necesitar fijar más que artefactos. **Módulos:** Process con Source/Database/CodeDocs/QA/Modeler, solo si se decide.

**Solución propuesta:** investigar un manifiesto que referencie versiones existentes y metadatos necesarios, sin clonar todas las entidades. **Alternativa:** mantener baseline actual y enlazar evidencias explícitamente, preferible mientras baste. **Aceptación/pruebas:** primero necesidad documentada y ADR aceptado, después esquema/versionado, reconstrucción de un release de ejemplo y migración/retención compatibles. **Dependencias:** C02–C04, C13 y validación de integraciones. **ADR:** obligatorio; propuesta de baselines describe la alternativa, no la aprueba. No iniciar en la consolidación inmediata.
