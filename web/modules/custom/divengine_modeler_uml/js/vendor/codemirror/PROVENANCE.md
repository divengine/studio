# Vendored CodeMirror 6 bundle

`codemirror.min.js` is an esbuild bundle of the CodeMirror 6 surface used by
the text editors (see `build/entry.js`), exposed as the global
`DivengineCodeMirror`. All bundled packages are MIT; their license texts are in
`LICENSES.txt`.

Direct dependencies (exact versions, full tree pinned by
`build/package-lock.json`): `codemirror` 6.0.2, `@codemirror/view` 6.43.13,
`@codemirror/state` 6.7.6, `@codemirror/language` 6.12.4,
`@codemirror/commands` 6.11.1; built with `esbuild` 0.28.2.

To rebuild (needs Node.js, outside the Drupal tree's runtime):

    cd build
    npm ci
    npm run build

`node_modules/` is never committed.
