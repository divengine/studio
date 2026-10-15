# Divengine Modeler: BPMN

BPMN 2.0 process diagrams on the Divengine modeler platform, edited with the vendored
bpmn-js modeler (see `js/vendor/bpmn-js/PROVENANCE.md`).

- Diagram type `bpmn`; notation plugin `bpmn` (canvas, XML, `.bpmn`).
- Validation: well-formed XML without DTDs, `bpmn:definitions` root, 1 MB limit.
- Element links: extension attributes in the Divengine namespace
  `https://divengine.com/schema/modeler/1.0` on the element itself,
  `divengine:entity="entity:artifact/7"` and `divengine:label="…"`, registered as a moddle
  extension so they survive export, import and revisions.
- Editor: "Link to a record" in each element's context pad (or the toolbar's
  "Link element" on the selection); linked elements show a badge that opens the target.
  Linking is an undoable command.
- Search text: element names, documentation and text annotations.
- The bpmn.io watermark stays visible, as its license requires.

Tests: kernel tests in `tests/src/Kernel`; the editor itself runs in jsdom with
`cd tests/js && npm ci && npm test`.
