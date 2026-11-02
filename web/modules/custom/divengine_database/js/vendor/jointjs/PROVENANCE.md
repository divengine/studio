# Vendored JointJS

- Package: `@joint/core` 4.3.3 (npm, published 2026-09-04), the open source core of
  JointJS. Versions 4.x are published under this name, not `jointjs`.
- Tarball: `https://registry.npmjs.org/@joint/core/-/core-4.3.3.tgz`
- npm integrity: `sha512-cAOpWbeUZ1le9dIkWCIWNLkzJtCHbhkszJYklytMM1BnuLTVQW9ADuHRRVxT9HZHqye/NRgYtlYDmwgeSiNNFw==`
- License: MPL-2.0 (`LICENSE`, copyright client IO). The file header carries the
  MPL-2.0 Exhibit A notice and no "Incompatible With Secondary Licenses" notice.

Files are byte-identical to the package (verified 2026-11-02):

| File here | File in the package |
|---|---|
| `joint.min.js` | `dist/joint.min.js` |
| `LICENSE` | `LICENSE` |

Never edit these files. MPL-2.0 applies file by file: a modified copy would have to stay
under MPL-2.0 with its source available.

To upgrade: `npm pack @joint/core@<version>`, compare the tarball's sha512 with
`npm view @joint/core@<version> dist.integrity`, copy the files above and update this
note and the version in `divengine_database.libraries.yml`.
