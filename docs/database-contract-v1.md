# Database snapshot contract v1

What an analyzer sends to Studio to describe the schema of a database at a moment. Studio keeps
exactly these facts and nothing about the analyzer, so any tool can feed it: produce this JSON, or
add an adapter for your format (a service tagged `divengine_database.contract_adapter`, see
`SchemaSpyAdapter`). Metadata only: never row data.

A complete example: `web/modules/custom/divengine_database/tests/fixtures/demo_shop_v1.json`.

```json
{
  "contract_version": "1.0",
  "database": { "code": "shop", "name": "Shop", "engine": "MySQL" },
  "snapshot": {
    "captured_at": "2026-10-01T09:00:00+00:00",
    "source": "my-analyzer", "source_version": "2.1",
    "source_revision": "4f2a9c1", "catalog": "shop"
  },
  "tables": [ … ],
  "foreign_keys": [ … ],
  "routines": [ … ]
}
```

| Part | Meaning |
|---|---|
| `database.code` | The **identifier** of the database in Studio (register it there first, or say which database to import into). |
| `database.engine` | MySQL, PostgreSQL… A database keeps the engine of its first snapshot. |
| `snapshot.source_revision` | What the schema was captured at (commit, release, migration). The snapshot key is `<identifier>@<revision>`, or `<identifier>@<first 16 hex of the content hash>` without it. |
| `snapshot.source`, `source_version`, `captured_at`, `catalog` | Shown to people; not part of the identity. |

**Tables** (`obj_type` `table` or `view`):

```json
{ "logical_key": "shop.customer", "name": "customer", "catalog": "shop", "obj_type": "table",
  "comment": "People who buy.", "view_definition": null,
  "columns": [
    { "logical_key": "shop.customer.id", "name": "id", "position": 1, "data_type": "int",
      "jdbc_type_code": 4, "size": 10, "scale": null, "nullable": false,
      "has_default": false, "default_value": null, "auto_updated": true, "comment": null,
      "pk_position": 1, "auto_increment": true, "charset": null, "collation": null, "enum_values": null }
  ],
  "indexes": [
    { "logical_key": "shop.customer.idx:uq_customer_email", "name": "uq_customer_email", "is_unique": true,
      "columns": [ { "column_logical_key": "shop.customer.email", "position": 1, "ascending": true } ] }
  ] }
```

- `logical_key`s are stable names, unique in the snapshot (tables, columns, indexes, relationships).
- `position` is 1-based; `pk_position` is the place in the primary key (0 = not in it).
- `has_default` distinguishes "no default" from a default that is the literal `NULL`.
- Optional enrichment: `charset`, `collation`, `enum_values`, `auto_increment` (columns),
  `view_definition` (views), `on_update` (relationships), `routines`. Send them when you have them;
  the schema diff compares them only when both snapshots do.

**Relationships** (`foreign_keys`): real ones (`implied: false`) are enforced by the database;
implied ones are an analyzer's guess (name and type match) and are kept apart.

```json
{ "logical_key": "shop.address.fk_address_customer_id", "name": "fk_address_customer_id",
  "implied": false, "on_delete_cascade": true, "on_update": null,
  "child_table_key": "shop.address", "parent_table_key": "shop.customer",
  "columns": [ { "position": 1, "child_column_key": "shop.address.customer_id",
                 "parent_column_key": "shop.customer.id" } ] }
```

**Routines** (optional): `logical_key`, `name`, `catalog`, `schema_name`, `routine_type`
(`procedure` | `function`), `return_type`, `parameter_signature`, `definition`, `deterministic`,
`sql_data_access`, `security_type`, `comment`, `created`, `modified`.

## Identity

The **content hash** is the SHA-256 of the canonical facts: the fields above (except `snapshot` and
`database`), lists sorted by `logical_key` (columns by position, indexes by name), optional fields
only when present, serialized as JSON with sorted keys and no whitespace. Studio computes it itself
(`Canonical::contentHash()`); producers do not need to send it.

## Sending it

```bash
# Command line
vendor/bin/drush divengine:database-import snapshot.json --revision=$(git rev-parse HEAD)

# HTTP, as an account with "Import database snapshots" that is a member of the Development
curl -u analyzer:PASSWORD -H "Content-Type: application/json" --data-binary @snapshot.json \
  "https://studio.example.com/api/divengine/database/import?database=DB-0001&revision=$GIT_SHA"
```

The answer says `imported` (201), `idempotent` (that key was already imported with this content) or
`unchanged` (same schema as the latest snapshot; add `force=1` to import anyway), with the snapshot
URL and its counts. Errors are `{"message": …}` with 422 (invalid contract) or 403.
