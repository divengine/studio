# ADR-0005: Links Between Diagram Elements and Drupal Entities

## Status

Accepted. Implemented in Phase 1 for BPMN and UML (see `docs/handoff-004.md`).

## Context

A diagram element (a BPMN task, a DMN decision, an ArchiMate application component, an
Excalidraw shape) often stands for something that already exists in Studio: a
requirement, a use case, a node, a user, another diagram. Engineers need to:

- link an element to that entity while modeling,
- open the entity from the diagram (editor, preview and embeds),
- see from the entity which diagrams reference it (backlinks),
- navigate from one diagram to another through linked elements.

## Decision

### Where the link lives

The link is part of the diagram document, stored on the element itself, so it survives
export, import and revisions:

- bpmn.io family, IFML and ArchiMate: extension attributes in a Divengine namespace,
  `divengine:entity="entity:node/42"` and `divengine:label="…"`, registered as a moddle
  extension in each engine. They are never added to a standard namespace.
- Excalidraw: the element's `customData.divengine = {entity, label}`.
- Text notations (UML, Mermaid): the source has no stable element ids, and the SVG is
  regenerated on every render. Links are stored in a structured field of the diagram,
  `element_bindings`, as a map from a **semantic key** to `{entity, label}`. The key
  identifies the element by meaning, not by position: element kind plus qualified name
  (`class:billing.Invoice`, `usecase:Pay invoice`), or for relations, kind plus both ends
  plus relation type. After each render the editor applies the bindings to the SVG
  elements that match exactly one key; bindings with zero or several matches are listed
  as unresolved for the user to re-bind, never guessed. Native link syntax written in the
  source with an `entity:` URI (PlantUML `[[entity:node/42]]`, Mermaid `click … href`)
  is also recognized by the extractor.

The value is Drupal's own `entity:<entity_type>/<id>` URI, the scheme already used by
core's link field, so any content entity can be a target: artifacts, nodes, users,
taxonomy terms, diagrams.

### Link index

A non-revisionable index, `diagram_element_link` (diagram id, revision id, element id,
target entity type, target id, label snapshot, broken flag), is rebuilt from the latest
revision's source on every save
by the notation plugin's `extractLinks()` (from the document for canvas notations, from
`element_bindings` plus native link syntax for text notations). The document is the
truth; the index exists for queries:

- **Backlinks**: "diagrams referencing this entity" is a query on target; any entity can
  show it in a block or tab.
- **Diagram-to-diagram links** are links whose target type is `diagram`; no extra table.
- **Integrity**: when a target is deleted, its rows are flagged and the editor shows the
  element as broken; the document is not rewritten silently.

### Picking a target

From the element's context pad (or the selected element) the editor opens a dialog that:

- searches across entity types the user can view (autocomplete over labels),
- accepts a pasted URL or path (`/node/42`, `/artifact/7`, `entity:node/42`) and resolves
  it through the router,
- returns `{entity, label}` to the editor, which writes the attributes on the element.

### Rendering

- In the editor, linked elements get a badge overlay; clicking opens the entity.
- In stored previews and embeds, linked SVG nodes get `data-entity-uri` attributes and
  become links.
- A link is rendered as a link only if the current user has `view` access to the target;
  otherwise the element shows as restricted. The label snapshot is never shown for
  entities the user cannot view.

### Relation to traceability

An element link is navigation, not a trace. When an engineer wants the link to carry
meaning (for example "this BPMN process realizes UC-12"), they create a `trace_link`
between the diagram and the entity. The diagram page offers that action next to each
element link, but never creates traces automatically (ADR-0002, explicit traceability).

## Consequences

### Positive

- Works for every entity type with no per-target code.
- Links travel with the document: export, re-import and revisions keep them.
- Backlinks and diagram graphs come from one table.

### Negative

- Each notation needs a moddle extension (or Excalidraw mapping) and an extractor.
- Text notations need a semantic-key builder per diagram kind (class, sequence, use case,
  component, flowchart…), and renamed elements lose their binding until re-bound.
- Label snapshots can go stale; they are refreshed on save and only used as a fallback.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| A field on the diagram listing linked entities | Loses which element is linked; breaks on import/export. |
| One record per diagram element | Large tables for no benefit; only linked elements matter. |
| Automatic traces from element links | Violates explicit traceability. |

## Related Decisions

- ADR-0002: Artifact and TraceLink domain model.
- ADR-0004: Modeler platform.
