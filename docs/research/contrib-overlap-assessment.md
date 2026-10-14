# Contrib Module Overlap Assessment

## Purpose

Evaluate existing Drupal contrib modules against Divengine Studio's domain requirements
to determine whether to adopt, reference, or reject them as architectural dependencies.

## Classification Key

| Label | Meaning |
|---|---|
| **Rejected as base** | Must not be used as a foundation for Divengine's domain model. |
| **Design reference** | Study the approach but do not depend on the module. |
| **Future integration** | May be evaluated after MVP for specific use cases. |
| **Research only** | No immediate plan; keep on radar. |

## Assessment

### Case Tracker
- **Classification**: Rejected as base
- **Rationale**: Built for IT help desk / case management workflows. Entity model does not
  map to engineering artifacts, decisions, requirements or trace links. Would require
  extensive overriding.

### Project
- **Classification**: Rejected as base
- **Rationale**: Generic project entity with no engineering trace semantics. The project
  concept in Divengine is not a container for tasks but a structured engineering context.
  Divengine will handle project-like grouping via its own domain model.

### Project Issue Tracking
- **Classification**: Rejected as base
- **Rationale**: Tightly coupled to the Project module. Issue tracking in Divengine is not
  bug tracking — it is requirement, decision, and evidence management with formal
  traceability.

### Drupal PM
- **Classification**: Design reference
- **Rationale**: Interesting approach to milestone and release management. The UX patterns
  for non-technical users are worth studying. However, built on Project module which is
  rejected as base.

### Relation
- **Classification**: Design reference
- **Rationale**: Generic entity relation system. Approach to bidirectional relationships
  is relevant for trace links. May inform custom traceability implementation. Not used as
  dependency because trace links have domain-specific semantics (type, rationale, confidence,
  review).

### Dynamic Entity Reference (DER)
- **Classification**: Future integration (technical spike)
- **Rationale**: DER 3.x provides dynamic target type selection which maps to trace link
  flexibility. However, it is a complex dependency with its own DX. Postpone until
  traceability UI proves the need. Do not add as dependency in MVP.

### Entity Usage
- **Classification**: Design reference
- **Rationale**: Tracks where entities are used/referenced. Relevant for "where is this
  requirement referenced?" queries. The approach to usage tracking is informative for
  Divengine's own reporting, but not needed at entity storage level.

### Track Usages
- **Classification**: Design reference
- **Rationale**: Similar to Entity Usage. Usage tracking in Divengine should be based on
  explicit trace links, not inferred references. Still, the UI patterns are worth knowing.

### Entity Mesh
- **Classification**: Design reference
- **Rationale**: Provides relationship management between entity types. The graph-like
  entity model is conceptually aligned. However, it adds a heavy dependency and its
  generic approach may conflict with Divengine's typed trace semantics. Study for UI
  inspiration.

### Workflow (contrib)
- **Classification**: Research only
- **Rationale**: Drupal core already provides Workflows and Content Moderation. Contrib
  workflow extensions add conditions, transitions, and permissions. Keep on radar for
  post-MVP if core workflows prove insufficient.

### ECA
- **Classification**: Research only
- **Rationale**: Event-action model for automation. Potentially useful for post-MVP
  automated validations ("when artifact is approved, create evidence item"). Do not add
  in MVP — keep domain logic explicit in code.

### BPMN.iO
- **Classification**: Research only
- **Rationale**: BPMN-based process modeling. Conceptually interesting but out of scope
  for MVP which only needs workflow-ready architecture, not process automation.

### Knowledge
- **Classification**: Design reference
- **Rationale**: Provides knowledge base entity types. Relevant to Divengine_knowledge.
  Study for field structure and UI patterns. Do not depend — Divengine needs engineering-
  specific knowledge semantics (standards, methods, evidence).

### Knowledge Graph
- **Classification**: Research only
- **Rationale**: Graph visualization for knowledge entities. Relevant for future
  visualization features but out of scope for MVP.

### AI / AI Knowledge Connector
- **Classification**: Research only
- **Rationale**: AI integration modules. Clearly post-MVP. Divengine's AI features should
  be built on the explicit trace and artifact model, not as a generic AI wrapper.

### Group
- **Classification**: Research only
- **Rationale**: Organic Groups for access control. Not needed for MVP. If multi-project
  isolation becomes a requirement, evaluate after MVP.

## Conclusion

1. **Do not use project/case/task management modules as the base.**
2. **Consider Dynamic Entity Reference only as a later technical spike.**
3. **Use Entity Mesh, Relation, Entity Usage, Track Usage and Drupal PM only as design
   references.**
4. **Keep Divengine's engineering domain model custom.**
