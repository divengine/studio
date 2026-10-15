# Handoff Report 005 — Phase 2: Embedding Diagrams in Text

## Summary

Diagrams can now be embedded in any formatted text field. CKEditor 5 has a "Diagram"
button: you search for a diagram, insert it, align it, or swap it for another. The text
stores `<drupal-diagram data-diagram-uuid="…" data-align="…">`. A text filter renders that
tag as the diagram's published preview, and its linked elements still respect access.

Studio also records where each diagram is embedded:

- The diagram page lists those places under "Embedded in".
- The delete form warns before an embedded diagram is deleted.

The module is installed on the site and is turned on in the Basic HTML and Full HTML
formats. Tests: 129 kernel tests pass (1857 assertions, 17 tests new) and 16 JavaScript
tests pass (4 new).

## Modules

### `divengine_diagram_embed` (new)

**Text filter `divengine_diagram_embed`**
- Type: transform reversible, weight 100.
- Embeds show the default revision of the diagram.
- The preview is shown in a sandboxed `<iframe>` pointing at
  `/diagram/{id}/preview/{hash}.svg?fit=1`.
- The frame keeps the diagram's aspect ratio and is never wider than the diagram's natural
  width (CSS custom properties).
- A caption links to the diagram page.
- Alignment comes from `data-align`, or from the `align-*` class that "Align images" leaves
  when it runs first.
- States:
  - ready
  - no preview
  - restricted: no label, nothing about the diagram is revealed
  - missing
- The cache tags and access cacheability of each embed bubble to the filter result.

**CKEditor 5 plugin**
- Plain JavaScript built on core's CKEditor 5 build, so there is no build step.
- Model element `divengineDiagram`: a `$blockObject` widget.
- The preview is fetched once per UUID per editor.
- The search dialog uses a native `<dialog>`.
- Widget toolbar: align left, center or right (toggles), and "Choose another diagram"
  (keeps the alignment).
- A PHP plugin class passes the endpoint URLs to the editor.
- The plugin requires the filter to be on in the same format.

**Endpoints**
- `GET /divengine/diagram-embed/search?q=`
- `GET /divengine/diagram-embed/lookup?uuid=`
- Both require `view diagrams` and check access to each diagram.

**Usage table `diagram_usage`**
- Columns: diagram, host type and ID, field, language, count.
- Rebuilt whenever the default revision of a host is saved, from its `text`, `text_long`
  and `text_with_summary` fields (value and summary).
- Hosts without such fields are skipped, so saving them adds no queries.
- Rows are cleaned up when a host, a translation or a diagram is deleted.
- Each diagram's list has the cache tag `diagram_usage:{id}`.
- `hook_install` scans existing content.

**Diagram page and delete form**
- "Embedded in" pseudo-field on diagrams: lists the hosts the user can view, plus a count
  of the ones they cannot.
- The delete form warns when the diagram is embedded.

### `divengine_modeler`

- `PreviewBuilder::build(..., $fit)`: with `?fit=1` the preview route sets the root to
  100% × 100% with `preserveAspectRatio`. It derives the viewBox from the width and height
  when the SVG has no viewBox, and removes the fixed size from `style`.
- `PreviewBuilder::dimensions()` gives the intrinsic size from the viewBox, or from the
  width and height.

## Decisions

The decisions are recorded in ADR-0004, "Embedding".

- **`<iframe>` rather than inline SVG.** An inline SVG would leak its `<style>` rules and
  element IDs (markers, gradients) into the page and collide with other embeds. An `<img>`
  cannot carry links. The sandbox only allows top navigation on user activation, and
  popups.
- **Default revision only.** Embeds and usage follow what readers see. A draft of a
  diagram does not change the documents that embed it until it is published.
- **Separate module.** The platform does not depend on `editor`, `filter` or `ckeditor5`.

## Verification on the site

**Before installing**
- Database dump taken first.
- Installed with `drush pm:install divengine_diagram_embed`. The install scan found nothing
  to record.

**Text formats**
- Basic HTML and Full HTML got the "Diagram" button after "Insert image", plus the filter.
- Basic HTML also allows `<drupal-diagram data-diagram-uuid data-align>`.
- Both pairs validated with `CKEditor5::validatePair` before saving.

**Demo page**
- Node 1 "Billing overview" (basic page, Full HTML) embeds diagram 1, centered, and
  diagram 2.
- Usage recorded: 1 each.

**Checked over HTTP**
- As admin:
  - Diagram 1 renders as a frame with ratio 135 / 331.
  - Diagram 2 shows "no preview yet".
- The framed preview returns the SVG at `width="100%"`, with CSP, `nosniff` and
  `X-Frame-Options: SAMEORIGIN` (same origin, so it can be framed).
- Anonymous visitors see "Restricted diagram." twice and no label.
- The diagram 1 page shows "Embedded in (1)", linking to the node.
- Endpoints:
  - search returns diagram 1;
  - a lookup of an unknown UUID returns 404;
  - an anonymous search returns 403.
- The node edit form carries the plugin settings, and the aggregated JavaScript contains
  the plugin.

## Known limitations

- **No real-browser test yet.** The plugin is tested in jsdom with core's CKEditor 5
  build, but layout, drag and drop and focus handling are not covered.
- **No pinning.** An embed cannot point to a specific revision.
- **Only formatted text fields are scanned.** Embeds in other places, such as custom
  blocks or Layout Builder overrides stored outside text fields, are not counted. Usage is
  also kept for removed text fields until the host is saved again.
- **Previews depend on a browser.** A diagram never opened in a browser (for example
  diagram 2) embeds as "no preview yet" until an editor views it.

Carried over:
- No real-browser test of the modelers.
- UML linking gaps.
- No PlantUML standard library.
- No UI for link target types.
- `composer audit` advisories.
- Unused data tables.

## Next

Phase 3: the remaining notations, starting with `divengine_modeler_mermaid` (text family,
UML pattern), then DMN and ArchiMate.
