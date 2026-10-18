# ADR-0013: Database schemas

## Status

Accepted

## Context

A Development's database is part of what people and AI agents need to understand next to its
requirements, code and tests. Analyzers already describe schemas (SchemaSpy, `information_schema`
extractors, ORM introspection…), each in its own format, and the schema changes over time.
CodeIntel's `codeintel_database` (Odoo) solves this; Studio reproduces its behaviour, explorer
included.

## Decision

Module `divengine_database`.

- **The contract is the seam.** Studio stores what the normalized contract v1 says (tables,
  columns, primary keys, indexes, real and implied relationships, routines, optional enrichment)
  and nothing about the analyzer that produced it. **Adapters** turn an analyzer's own format into
  the contract; they are services tagged `divengine_database.contract_adapter`. Two ship: the
  contract itself (JSON) and SchemaSpy XML. Metadata only: never row data.
- **Databases** (`schema_database`, `DB-` code) belong to a Development and follow its visibility.
  Analyzers address them by **identifier** (`database.code` of the contract); once a database has
  snapshots, its identifier and engine are fixed.
- **Snapshots** (`schema_snapshot`) are written by the importer only and never change once ready.
  Identity is computed server-side: the SHA-256 of the canonical content (fixed key set, pinned
  order, enrichment only when present: the same hash as CodeIntel's producer) and the key
  `<identifier>@<revision>` (or `@<hash prefix>`). Same key and content: idempotent; same key,
  other content: refused; content equal to the latest snapshot: reported as unchanged.
- **Facts** live in module tables (`hook_schema`), keyed by snapshot, because a schema has
  thousands of columns: `divengine_db_table`, `_column`, `_index`, `_index_column`, `_fk`,
  `_fk_column`, `_routine`. The import is one transaction with bulk inserts.
- **Findings** (`divengine_db_finding`) are derived by deterministic rules after the import and
  never fail it; recomputing replaces them.
- **Feeding**: an import form, `drush divengine:database-import`, and `POST
  /api/divengine/database/import` with HTTP Basic (core `basic_auth`, only on that route) for an
  analyzer account with "Import database snapshots" that is a member of the Development.
- **Schema explorer**: JointJS core 4.3.3 + ELK 0.12.0 (layered, organic, radial layouts in a Web
  Worker, level of detail, culling, hub collapsing, minimap, graphics presets), fed by a JSON API
  (index, details in batches, search). Its modules are framework-free; the page controller is an
  OWL component, mounted with standalone OWL 2.8.3 (LGPL-3.0). All three libraries are vendored and
  load only on those pages.
- **Schema evolution**: a semantic, directional diff of two snapshots (no rename inference,
  enrichment-only properties compared only when both sides have them, technical severity), computed
  on request and never stored, shown as a filterable change tree with report and exports, and as a
  visual diff (union of both snapshots, removed objects as ghosts) in the explorer.
- **MCP** plugin `database`: `database_databases`, `database_tables`, `database_table`,
  `database_findings`, `database_changes`, limited to what the connected account may see.

## Consequences

- Any analyzer can feed Studio by producing the contract or by adding an adapter; Studio does not
  run analyzers nor connect to the databases.
- Manual positions in the explorer are a browser preference (`localStorage`), not shared.
- Sequences, triggers and check constraints are not in the contract yet, so they are not compared.
