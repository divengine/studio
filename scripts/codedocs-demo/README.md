# Shop Code Docs examples

Creates three small, invented projects in `~/workspace/codedocs-demo`: a PHP catalog
and checkout library, a TypeScript basket and API client, and Java order services. The sources in this
folder are templates: existing workspace projects are never overwritten.

Requirements: a working Studio installation with Code Docs, Code Source and MCP,
PHP CLI, Python 3, Git, Node/npm, a JDK (javac, javadoc), and CodeIntel's existing
TypeScript extractor and Javadoc JSON doclet.

```bash
bash scripts/codedocs-demo/refresh.sh
# Optional: add a documented promotion and capture a second snapshot.
python3 scripts/codedocs-demo/evolve.py
bash scripts/codedocs-demo/refresh.sh
```

Set `CODEDOCS_DEMO_ROOT` to change the workspace path; set
`CODEINTEL_TS_EXTRACTOR` to change the extractor path (default:
`~/workspace/dev/tsdoc/extractor/extract.mjs`) and `CODEINTEL_JAVADOC_DOCLET` for the
doclet (default: `~/workspace/dev/javadoc/doclet`; built on first use). The TS project installs its own
TypeScript compiler. The PHP reflection extractor loads only the three known,
trusted demo classes; it is an example, not a general parser for untrusted PHP.

The script creates a **Code Docs Demo - Shop** Development, registers each Git
repository and documentation set, indexes the source and imports real extractor
output using Drush and the same importer used by HTTP publishing. It switches to
the site administrator only inside this local Drush process; no passwords are
created, printed or stored. Remote CI can instead publish the generated JSON with
`web/modules/custom/divengine_codedocs/tools/publish.py` and a publisher account.

The script enables the Code Docs MCP plugin, grants the MCP role read permission
and adds the configured MCP account to this demo Development only. It preserves
the other plugin settings and Development memberships. Generated JSON and a
manifest with record IDs live in the workspace's `output/` directory, outside the
Studio Git repository.

Modify the workspace projects, commit the source changes and rerun `refresh.sh`.
Repeated runs with unchanged commits do not duplicate documentation snapshots.

Read the populated projects through the real MCP endpoint as the agent:

```bash
python3 scripts/codedocs-demo/read-mcp.py
```

The script uses the existing private token file without printing it. Change
`--url` or `--token-file` for another installation. It lists Code Docs tools and
reads the example symbols and HTTP endpoints using that account's permissions.

To capture the actual application screens on a host with Puppeteer/Chromium:

```bash
node scripts/codedocs-demo/capture.cjs /opt/tools/node_modules/puppeteer
```

Screenshots are saved under `output/screens` in PNG and JPEG. The capture script
uses a one-time Drush login in memory, and does not save tokens, login URLs or
browser sessions. It needs to run as the trusted site operator.
