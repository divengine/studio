# Documentation index

Studio's documentation has two kinds of documents. **Maintained** documents describe the
current code and are corrected in the same pull request as the change they describe.
**Archived records** describe the project at a past date. They are kept as engineering
history and are not updated: their commands, test counts and limitations are those of
that date. Each one starts with a note that says so.

Removing an archived record from the branch would not remove it from the Git history, so
records are labelled instead of deleted. A record that contained something unsuitable for
a public repository is corrected in place and the reason is recorded in the pull request.

## Maintained

| Document | Content |
|---|---|
| [architecture/](architecture/) | Architecture decision records (ADR-0001 to ADR-0021; there is no ADR-0003) |
| [development.md](development.md) | Development environment, tests and safe use of test databases |
| [releasing.md](releasing.md) | Versioning, stages and the release checklist |
| [roadmap.md](roadmap.md) | Current scope, release milestones and later phases |
| [database-contract-v1.md](database-contract-v1.md) | The database snapshot contract for analyzers |
| [development/testing.md](development/testing.md) | Pointer to the tests section of `development.md` |
| Module READMEs under `web/modules/custom/*/` | How each module works and is used |

## Archived records

| Record | Date | What it is | Follow-up |
|---|---|---|---|
| [handoff-002.md](handoff-002.md) to [handoff-005.md](handoff-005.md) | 2026-10-14 to 2026-10-15 | Development handoffs of the first phases: Drupal 11 modernization, foundation, modeler platform, diagram embedding | Roadmap phases 0–2 |
| [mvp/mvp-scope.md](mvp/mvp-scope.md) | 2026-10-14 | The initial MVP scope | Superseded by the roadmap and the CHANGELOG |
| [research/contrib-overlap-assessment.md](research/contrib-overlap-assessment.md) | 2026-10-14 | Which Drupal contrib modules Studio adopts or rejects as a base, and why | Background for ADR-0001 and ADR-0002 |
| [audits/2026-10-18/](audits/2026-10-18/) | 2026-10-18 | Consolidation audit in Spanish: architecture, coherence of claims, defects, tests, backlog and iteration plan, with reproducible evidence | Issues #1–#20 (C01–C17) |
| [iterations/](iterations/) | 2026-10-19 to 2026-10-20 | Reports, in Spanish, of the consolidation iterations that fixed C01–C08 (PRs #21 and #33) | Open conditions in #23 |

The audit describes security defects found on 2026-10-18. They were fixed and deployed
before any release (#1–#3 and #4–#8), so the descriptions and probes no longer apply to
the code. Report a new vulnerability as described in [SECURITY.md](../SECURITY.md), not in
an issue.

The archived records written in Spanish are not translated. Their English summary is the
GitHub issue each one links to.
