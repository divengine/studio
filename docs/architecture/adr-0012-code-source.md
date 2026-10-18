# ADR-0012: Code Source

## Status

Accepted

## Context

A Development's code lives in Git repositories on disk. People and AI agents need to see it
next to the requirements, tasks and tests, without Studio becoming a Git host or a copy of
the code. CodeIntel's `codeintel_source` (Odoo) solves this; Studio reproduces its behaviour.

## Decision

Module `divengine_source`.

- **Repositories** (`source_repository`, `SRC-` code) belong to a Development and follow its
  visibility. Their path must lie inside the **allowed roots** (setting, default `/opt/repos`),
  checked on save and on every Git access (real path, symbolic links and `..` resolved). Once
  something was indexed, the path cannot change.
- **Refs** (`source_ref`): branches, tags or remote-tracking refs that exist in the clone,
  indexed explicitly (button or `drush divengine:source-index`).
- **Snapshots** (`source_snapshot`): an indexing of a ref at a commit; ready snapshots never
  change. Indexed commits are pinned under `refs/codeintel/snapshots/<sha>`.
- **Git contract**: `rev-parse`, `ls-tree`, `cat-file`, `grep` on a commit and `update-ref` in
  Studio's namespace only, as an argument list with a timeout. No clone, fetch, checkout; the
  working tree is never read.
- **The index** lives in three module tables (`hook_schema`), because a repository has tens of
  thousands of paths: `divengine_source_file` (identity of a path), `divengine_source_revision`
  (one row per file and blob, metadata only) and `divengine_source_state` (presence and current
  revision of each file in each ref). **No code is stored**: files are read from Git by blob
  SHA when someone looks, up to a configurable size.
- **Code browser**: folder tree, ref switcher, viewer with line numbers (`#L42` links) and
  highlight.js (vendored, BSD-3), raw view for downloads and images (served with `nosniff` and
  a restrictive CSP).
- **MCP** plugin `code`: `code_repositories`, `code_tree`, `code_find_files`, `code_read`
  (line ranges) and `code_search` (git grep), limited to what the connected account may see.

## Consequences

- Keeping clones up to date (fetch, pull) is the operator's job; Studio only reads them.
- The web server user must be able to read the clones and to write in their `refs/` for the pins.
