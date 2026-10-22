# Governance

Divengine Studio is an open source project licensed under GPL-3.0-or-later and led by its
maintainer. This document describes who decides what, and how contributions from the
community are handled.

## Roles

**Maintainer.** [@rafageist](https://github.com/rafageist), the
repository administrator, maintains Divengine Studio. The maintainer:

- decides the scope, architecture, roadmap and priorities of the project;
- triages issues and decides their type, priority, milestone and project status;
- reviews pull requests and decides whether, when and how they are merged;
- accepts or rejects architecture decision records (ADRs);
- decides when a release is made and what it is called (see
  [docs/releasing.md](docs/releasing.md));
- handles security reports (see [SECURITY.md](SECURITY.md)) and enforces the
  [Code of Conduct](CODE_OF_CONDUCT.md);
- manages repository settings, access and automation.

The maintainer may delegate review or triage of specific areas to other people. Such
delegation is recorded in [.github/CODEOWNERS](.github/CODEOWNERS) or in this document;
it is not implied by commit access or by previous contributions.

**Contributors.** Anyone who reports a bug, proposes a feature, improves documentation,
reviews a pull request or submits code is a contributor. Contributors are credited
through the Git history and pull requests.

## How decisions are made

- **Proposals.** Issues and pull requests from anyone are proposals. They become planned
  work only when the maintainer accepts them, for example by assigning them to a project
  or milestone or by stating so in the issue. An external proposal is not approved
  implementation, and no proposal is accepted automatically.
- **Architecture.** Significant decisions are recorded as ADRs in
  [docs/architecture](docs/architecture). New ADRs, and changes to accepted ones, are
  proposed in an issue or pull request and accepted by the maintainer.
- **Roadmap.** The direction is described in [docs/roadmap.md](docs/roadmap.md) and in the
  repository's issues and project board. The maintainer updates them.
- **Merges.** Changes reach `main` through pull requests reviewed and merged by the
  maintainer. Passing CI is required but not sufficient for a merge.
- **Releases and deployment.** Merging does not release or deploy anything. Tags and
  GitHub Releases are created only when the maintainer approves them.

The maintainer explains decisions in the issue or pull request where they are made, and
welcomes discussion there. The final decision rests with the maintainer.

## Triage

New issues are triaged by the maintainer:

1. Security reports filed publicly are hidden or closed and the reporter is directed to
   [SECURITY.md](SECURITY.md).
2. Duplicates, support questions and out-of-scope requests are closed with an
   explanation.
3. Accepted issues receive an `area: …` label, a native issue type and, when planned,
   a place on the project board with its priority. These are set by the maintainer only;
   issue forms do not set priorities or milestones.
4. Issues suitable for newcomers may be labelled `good first issue`; issues where outside
   help is welcome may be labelled `help wanted`.

## Changes to this document

Changes to this governance are proposed through a pull request and decided by the
maintainer.
