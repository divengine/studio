# Divengine Modeler: UML

UML diagrams written in PlantUML, rendered entirely in the browser by the vendored
`@plantuml/core` engine (no server, no Java), with a CodeMirror 6 editor. See
`js/vendor/plantuml/PROVENANCE.md` and `js/vendor/codemirror/PROVENANCE.md`.

- Diagram type `uml`; notation plugin `uml` (text, `.puml`). Any PlantUML diagram that
  starts with `@start…` and ends with the matching `@end…` is accepted.
- Safety: the source is limited to 1 MB and must be self-contained: every `!include…` and
  `!import`, themes `from` a URL, `%load_json`, images by address and script links are
  refused (server and editor), so rendering never fetches anything.
- Split editor: source on the left, live render on the right (debounced), errors with a
  "Go to line" link, linked elements on the side. Clicking an element jumps to its source
  line.
- Element links are **bindings** stored next to the source (`element_bindings`), keyed by
  a semantic key read from the rendering:
  - `entity:<qualified name>` — classes, interfaces, actors, use cases, components,
    packages… (`entity:billing.Invoice`, `entity:UC1` for `usecase "…" as UC1`)
  - `link:<from>-><to>:<type>` — relations between two of them
  - `participant:<name>` — sequence diagram lifelines
  Double-click an element (or select it and use "Link element") to link it. A binding
  whose key matches no element, or several, is listed as unresolved or ambiguous and is
  never applied by guess. Renaming an element unbinds it until it is linked again.
- Native links written in the source, `[[entity:node/42 label]]`, are indexed too.

Known limitations: sequence messages, activity nodes and other elements the engine does
not tag have no key yet; native `entity:` links are indexed but the engine does not draw
them as links.

Tests: kernel tests in `tests/src/Kernel`; the notation runs against the real engine in
jsdom with `cd tests/js && npm ci && npm test`.
