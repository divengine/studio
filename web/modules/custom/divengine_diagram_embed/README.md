# Divengine Diagram Embed

Embeds Studio diagrams in formatted text (ADR-0004, "Embedding").

- **Markup**: `<drupal-diagram data-diagram-uuid="…" data-align="left|center|right">`
  `</drupal-diagram>`. The UUID survives export and import; `data-align` is optional.
- **Text filter** `divengine_diagram_embed` ("Embed diagrams", weight 100, after "Align
  images" if both are on): renders the stored preview of the diagram's default
  (published) revision in a sandboxed `<iframe>` pointing to the platform preview route
  with `?fit=1`, so the SVG scales to the frame, its styles stay inside it and its linked
  elements open, in the top window, only the records the viewer may see. The frame keeps
  the diagram's aspect ratio and never grows beyond its natural width. A caption links to
  the diagram page. Viewers who cannot see the diagram get "Restricted diagram." with no
  label; unknown UUIDs show "Missing diagram."; diagrams never rendered show "This diagram
  has no preview yet." Results carry the diagram's cache tags and the access result's
  cacheability.
- **CKEditor 5 plugin** `divengine_diagram_embed_diagram`, toolbar item "Diagram": a
  dialog searches the diagrams the user can view and inserts the chosen one; the widget
  shows its preview as an image, with a toolbar to align it or choose another diagram.
  It is plain JavaScript written against the CKEditor 5 build shipped by core
  (`CKEditor5.core`, `.widget`, `.ui`), so there is no build step. The plugin requires the
  filter to be enabled in the same text format, and `filter_html` (when on) must allow
  `<drupal-diagram data-diagram-uuid data-align>`.
- **Endpoints** (`view diagrams`): `GET /divengine/diagram-embed/search?q=` (up to 20
  viewable diagrams, most recently changed first) and
  `GET /divengine/diagram-embed/lookup?uuid=` (404 missing, 403 restricted).
- **Usage table** `diagram_usage` (diagram, host entity type and ID, field, language,
  count), rebuilt from the formatted text fields (`text`, `text_long`,
  `text_with_summary`, value and summary) every time a host's default revision is saved;
  forward drafts do not count. Host deletion, translation deletion and diagram deletion
  clean it up. Installing the module scans existing content.
- **"Embedded in"** pseudo-field on diagrams: hosts the user can view, plus a count of the
  ones they cannot. The delete form warns when the diagram is embedded.

## Enabling it in a text format

In *Configuration → Text formats and editors*, edit a CKEditor 5 format, drag "Diagram"
into the toolbar and enable "Embed diagrams". With "Limit allowed HTML tags" on, CKEditor
adds `<drupal-diagram data-diagram-uuid data-align>` to the allowed tags by itself.

## Tests

- Kernel: `vendor/bin/phpunit -c phpunit.xml web/modules/custom/divengine_diagram_embed`.
- JavaScript (the plugin inside the CKEditor 5 build of core, in jsdom):
  `cd tests/js && npm ci && npm test`.
