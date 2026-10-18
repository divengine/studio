# Code docs

One model for technical documentation extracted from any programming language.
Studio stores documentation snapshots; local tools extract code and publish JSON.
OpenAPI specifications belong to a separate feature.

The module includes adapters for the existing CodeIntel Java JSON doclet and
TypeScript extractor. PHP, C#, C++, JavaScript and other tools can send the common
contract directly, or register another adapter service tagged
`divengine_codedocs.adapter`. No additional Drupal module is needed per language.

## Setup

Enable `divengine_codedocs` and its dependencies, or apply the Studio recipe.
On a site with the initial skeleton already enabled, apply database updates and
rebuild caches when deploying. This change does not run those operations.

Open a Development's **Code docs** tab and register a documentation set. Its unique
identifier must equal the JSON `project` field, e.g. `shop-backend`. Each set
represents one codebase and language. Optionally link a Code Source repository
and the folder containing the extractor's paths.

Permissions:

| Permission | Purpose |
| --- | --- |
| `use code docs` | Read documentation in visible Developments |
| `manage code docs` | Register sets and import snapshots in visible Developments |
| `publish code docs` | Upload through the API as a Development member, responsible or owner |
| `administer code docs` | Manage history and delete ready snapshots in visible Developments |

The recipe grants readers access, gives Studio Manager management permission,
and creates **Code docs publisher** for CI accounts. Assign the publisher account
to each Development it feeds. Publishing alone does not grant read access.
Existing sites may grant these permissions manually or reapply the recipe.

## Publish existing extractor output

Run the current Java or TypeScript extractor as usual. Its JSON needs no changes.
Use the module's standard-library Python publisher instead of the Odoo publisher:

```powershell
$env:STUDIO_URL = 'https://studio.example.com'
$env:STUDIO_USER = 'docs_publisher'
$env:STUDIO_PASSWORD = 'account-password'
python web/modules/custom/divengine_codedocs/tools/publish.py path/to/extracted.json
```

Set the same environment variables in your shell or CI, and call this command
after extraction in `refresh-all.sh`. `--chunk-mb 1`, `--format auto`, `--force`
and `--dry-run` are supported. Credentials stay in environment variables.
HTTPS is required except on localhost. The publisher discards incomplete uploads
after errors; if the server is unreachable it prints the upload ID for cleanup.
A single type/unit is never split, so exceptionally large units may require a
larger server request limit.

Small files can also be imported from the documentation set's **Import** tab.

## Common JSON contract, version 1.0

```json
{
  "contract": "divengine.codedocs",
  "contract_version": "1.0",
  "project": "billing-backend",
  "language": "php",
  "producer": "my-extractor/1.0",
  "commit": "full-git-sha-or-release",
  "branch": "main",
  "captured_at": "2026-10-18T12:00:00Z",
  "units": [{
    "key": "src/Invoice.php",
    "kind": "file",
    "path": "src/Invoice.php",
    "symbols": [{
      "key": "Billing\\Invoice",
      "kind": "class",
      "name": "Invoice",
      "exported": true,
      "doc": {"summary": "An invoice."},
      "members": [{
        "key": "Billing\\Invoice::total()",
        "kind": "method",
        "name": "total",
        "visibility": "public",
        "signature": "total(): Money",
        "type": "Money",
        "line": 24,
        "refs": [{"kind": "return", "target": "Billing\\Money"}]
      }]
    }]
  }]
}
```

Keys identify units and symbols within a snapshot. They must be stable across
captures; overloaded methods need distinct keys including parameter types.
Members may be nested or flattened with `parent` naming another symbol's key.

| Object | Fields |
| --- | --- |
| Unit | `key`, `kind`, `name`, `path`, `parent`, `language`, `category`, `is_test`, `lines`, `doc`, `extra`, `symbols`, `refs`, `endpoints` |
| Symbol | `key`, `kind`, `name`, `qualified_name`, `parent`, `visibility`, `modifiers[]`, `signature`, `type`, `value`, `file`, `line`, `end_line`, `exported`, `generated`, `deprecated`, `doc`, `extra`, `params`, `refs` |
| Documentation | `summary`, `description`, `tags[{kind,name,text}]` |
| Parameter | `kind` (`param`, `type_param`, `component`), `name`, `type`, `optional`, `rest`, `default`, `doc` |
| Reference | `kind`, `target`, `display`, `external`, `line`, `count`, `from`, `detail` |
| Endpoint | `kind` (`route`, `http_call`, or extractor-defined), `method`, `path`, `full_path`, `symbol`, `raw`, `line`, `detail` |

All collections are JSON arrays. Keys and paths support up to 1024 characters;
symbol names 255; kinds 32. Additional language-specific facts belong in `extra`
or `detail`. Java annotations, nested type references and extractor details are
preserved. Documentation is rendered as text, so imported HTML is not executed.

## HTTP API

Base path: `/api/divengine/codedocs/`. HTTP Basic authentication works on these
routes; session-cookie writes require Drupal's `X-CSRF-Token` header.
Validation errors return JSON `{ "message": "..." }` with HTTP 422, access errors
403. Register the set first; ingestion never creates Developments or sets.

| Method and path | Body / query |
| --- | --- |
| `POST import` | Complete JSON document; optional query `format`, `force` |
| `POST begin` | `{ "header": { ...document without large arrays... }, "chunks": 3, "format": "auto" }` |
| `POST chunk` | `{ "snapshot": 123, "sequence": 0, "data": { "units": [...] } }` |
| `POST finalize` | `{ "snapshot": 123, "force": false }` |
| `POST discard` | `{ "snapshot": 123 }` |
| `GET sets` | Visible sets, identifiers and latest snapshot IDs |
| `GET snapshots` | `project`; latest 100 ready snapshots and content hashes |
| `GET units` | `project`, optional `snapshot`, `offset`; 200 rows |
| `GET symbols` | `project`, optional `snapshot`, `q`, `offset`; 100 rows |
| `GET symbol` | `project`, `symbol` ID, optional `snapshot`; params, tags, members, outgoing/incoming refs |
| `GET refs` / `GET endpoints` | `project`, optional `snapshot`, `offset`; 200 rows |
| `GET diff` | `project`, `from` snapshot ID, optional destination `snapshot` |

Format IDs: `contract`, `javadoc`, `tsdoc`, or `auto`. Chunk arrays are `units`
for the common contract, `packages` and/or `types` for Java, `modules` for TS.
Chunks are numbered from zero and can arrive in any order. Retrying the same
sequence with the same JSON is idempotent; changing its content is refused.
Duplicate unit/symbol keys are refused. Each chunk is atomic and owned by its
publisher. All declared chunks must arrive before finalization.

Finalization resolves references within the snapshot, validates symbol parents,
computes counts, documentation coverage and a server-side content hash, then
publishes the snapshot. Unresolved external references remain textual. Only
ready snapshots are readable. Uploads and ready history cannot be modified by
other publishers. A ready snapshot is immutable; the same revision with different
content is refused. Equal content returns `unchanged` unless forced; identical
revision/content returns `idempotent`. Positions in files do not affect diffs.

Coverage counts public/exported, handwritten symbols; generated declarations and
overrides that inherit documentation are excluded. The snapshot page compares
against its preceding capture; the API can compare any pair in the same set.
Source links appear when an indexed repository ref matches the documented commit.

When Drupal MCP is enabled, the `codedocs` plugin exposes sets, snapshots, units,
symbols, symbol details, endpoints and diffs with the connected user's access.
Installation and updates enable its configuration when missing and grant the MCP
agent role read access; existing explicit plugin settings and authentication are
preserved. The MCP recipe and `divengine:mcp-setup` also include this integration.
See `scripts/codedocs-demo/README.md` for runnable PHP/TypeScript examples,
extraction/import scripts, real MCP reads and authenticated screenshot capture.

## Development checks

`tests/fixtures` contains neutral Java, TypeScript and PHP contract examples.
`php web/modules/custom/divengine_codedocs/tests/run.php` runs isolated contract
and database integration checks using Drupal's database API with SQLite in memory.
It needs the production Composer dependencies and `pdo_sqlite`, and does not
read or write a configured site's database.
