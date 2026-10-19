# Propuesta de ADR: Development como contexto de ingeniería

Estado: **Proposed — pendiente de revisión; no modifica ADR aceptados**.

## Contexto y evidencia

Development pertenece a `divengine_process`, pero QA, Source, Database, CodeDocs y artículos lo utilizan para alcance y autorización. Project es independiente por ADR-0009, Diagram permanece global por ADR-0004 y los artículos usan `kb_development`. Los guards, queries y borrado no reconocen uniformemente estas diferencias (F01/F07/F08).

El problema inmediato es la consistencia de esos contratos, no el nombre o la ubicación de una entidad. No hay evidencia de un despliegue que quiera Source/Database/CodeDocs sin Process; extraer un contexto nuevo ahora impondría una migración sin necesidad demostrada.

## Alternativas

| Alternativa | Cohesión y acoplamiento | Compatibilidad y migración | Extensibilidad / complejidad |
| --- | --- | --- | --- |
| A. Mantener Development en Process y reconocer su papel compartido | Mayor cohesión metodológica, dependencias de ingeniería hacia Process ya existentes | Compatible; sin mover entidad, referencias ni IDs | Mejorar registro de ámbitos, reglas de borrado y API de acceso; complejidad baja. No permite instalar áreas dependientes sin Process. |
| B. Nuevo contexto genérico, vinculado a un Development UP | Separa contexto/autorización de metodología y facilita otras metodologías | Exige migrar referencias/grants/Views/roles/API y resolver uno-a-uno o uno-a-varios; riesgo de doble identidad | Mayor independencia a costa de entidad/módulo y transición complejos; justificar con un consumidor real. |
| C. Mantener entidades y usar una abstracción Drupal/Studio para consultar su ámbito | Entity Reference, access handlers, grants y hooks permiten resolver pertenencia sin entidad nueva | Compatible; normalizar adaptadores de `development`, `kb_development`, proyecto y hosts | Buen contrato para acceso/referencias y extensiones, pero no elimina la dependencia de los datos hacia Process. Evitar un framework genérico de ACL. |

## Recomendación para revisión

Combinar A con una versión pequeña de C: conservar identidad, storage y URLs de Development; documentar que sirve de contexto a otras áreas; centralizar únicamente la resolución necesaria para acceso de extremos y enumeración de dependientes. Usar Entity API y los hooks existentes cuando basten. No introducir una nueva entidad ni reemplazar los handlers de Drupal.

Esta recomendación **no está adoptada**. Las correcciones P0 pueden comprobar acceso de extremos sin esperar una extracción arquitectónica. Si aparece una necesidad real de instalar Source/CodeDocs sin UP, revisar B en otro ADR con datos de migración y coexistencia.

## Límites y consecuencias

* Project conserva su visibilidad y jerarquía; una traza no concede membresía.
* Diagram conserva el ámbito aceptado; vincularlo no lo mueve implícitamente al Development.
* Los artículos mantienen grants nativos; el resolver no debe sustituirlos.
* La política por cuenta sigue perteneciendo a entity access/query access y debe conservar cacheabilidad.
* Un registro debe poder declarar que impide borrar el contexto, sin depender de su nombre de campo ni borrar conocimiento en cascada.

## Criterios para aceptar una decisión futura

Una prueba de integración con Artifact, QA, Project, Article, Source y CodeDocs debe demostrar visibilidad coherente de referencias y prevención de huérfanos. Debe existir matriz de instalación opcional y plan de compatibilidad de APIs. Para adoptar B, además se requieren migración reversible, mapa de IDs y grants, consumidores identificados y medición del coste operativo. No cambiar ADR-0007/0009 tácitamente.
