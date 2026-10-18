# ADR-0011: Knowledge base, discussions and links by code

## Status

Accepted

## Context

Teams need to document a Development (and the organisation) like Odoo Knowledge does, and
every record needs what Odoo's `mail.thread` gives: a discussion with attachments. Drupal
already ships most of the pieces (nodes, taxonomy, comments, files, text formats).

## Decision

### Discussions (`divengine_thread`)

- Every Studio record type gets a comment type `thread_<entity type>` and a `thread` comment
  field: an optional message and private attachments (`thread_files`), so a post can be just
  files. Task comments become their discussion with attachments.
- Modules declare their types with `hook_divengine_thread_types_alter()`; new bundles get a
  thread on creation; records created earlier are treated as open.
- The thread is added by `hook_entity_view()` (whatever the page layout) and shown as a side
  panel on wide screens, with an *Attachments* list of the files posted.

### Knowledge base (`divengine_articles`)

- A *Knowledge article* node type: KB code, parent article, optional Development, topics
  (knowledge_domain), revisions, body with embedded diagrams.
- Access by Development through **node access grants** (realm `divengine_development` for a
  Development's documentation, `divengine_knowledge` for general articles).
- Pages: Knowledge index (trees per Development), Knowledge tab of a Development, article header,
  sub-article cards, previous/next, breadcrumbs.

### Links by code (`divengine_traceability`)

- `[[CODE]]` / `[[CODE|text]]` in formatted text becomes a link to the record with that code
  (text filter `divengine_record_references`), with the current title, *restricted* when the
  reader cannot see it, marked when the code is unknown.
- Mentions are indexed on save (table `divengine_reference`) and records show *Mentioned in*.
  Mentions are not traces: traces stay the explicit, typed relationships.

## Consequences

- Attachments and article evidence need the private file system.
- Articles are nodes, so search, paths and the wider Drupal ecosystem apply to them.
