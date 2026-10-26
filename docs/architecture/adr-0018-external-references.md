# ADR-0018: External References as Development Records

## Status

Accepted for the first increment of #68 (#69). The choices marked **review** were taken
as defaults and are open to maintainer review on #68.

## Context

Studio is meant to be the engineering system of record for requirements, decisions and
verification evidence. The implementation work for them is tracked elsewhere: issues and
pull requests on GitHub (or another forge). Studio needed a way to say "this pull request
implements REQ-0042" and "that pull request verifies it":
- without copying GitHub's issues into Studio tasks;
- without requiring GitHub to be reachable to show what is recorded;
- without weakening the privacy of Developments.

What already existed:

- Trace links are polymorphic (entity type and ID at each end), with typed relations
  (`implements`, `verifies`, `references`…), endpoint access, integrity locks, mandatory
  revisions and baseline retention (#8, #21, #33).
- Records of a Development follow its visibility through `development` fields, and
  `TraceRules` keeps links inside one Development.
- `source_repository` represents *local, indexed Git clones*. It is not a reference to
  remote work.

## Decision

A new module, `divengine_external`, installed by the base recipe, adds one content
entity, `external_reference`, and nothing else is changed in the trace model:

1. **A reference is a record of one Development (review).** It follows the visibility of
   its Development, takes part in the existing trace rules and blocks deleting the
   Development. The same GitHub issue used by two Developments is two references, so a
   reference never reveals one Development to another.
2. **Identity, not URL.** Its identity is provider + host + namespace + kind + remote ID,
   read from the link by a provider plugin:
   - it is unique within a Development (validation, then a lock and a re-check on save);
   - it never changes after the reference is registered;
   - a different link to the same work reuses the reference;
   - the canonical link is derived from the identity.
3. **No network access.** Providers recognize links deterministically, so a reference can
   be registered and read while the provider is unreachable. Only http and https links to
   host names are accepted, without credentials. Enriching references with data read from
   the provider (title, state) is a separate, optional step (#70).
4. **Studio's data and the provider's data stay apart.** A reference holds the link, a
   label and Studio's own notes, all revisioned. It holds no copy of the external work, and
   approval stays with Studio's own records (requirements, decisions, evidence).
5. **References that are in use cannot be deleted (review).** A reference with trace links
   is kept until the links are removed. Baselines keep the trace revisions that point to
   it, and its identity cannot change.
6. **Permission.** "Link external work" plus editing the Development. Linking also needs
   "Create trace links" and seeing the record. The base recipe grants it to the roles that
   create trace links.
7. **Linking in one step.** "Link external work" in a record's Traces resolves the
   reference (registering it or reusing it) and creates the typed trace link through the
   `divengine_external.references` service. The MCP write tools (#71) are to use the same
   service.

8. **Observing is optional, manual and read-only (#70).** When the site allows it, people
   who may edit a GitHub reference can ask GitHub for its title and state:
   - only `api.github.com` is contacted, anonymously, without redirects, with bounded
     time and size, at most once a minute per reference, and never while GitHub asks
     Studio to wait;
   - the answer is stored apart from the reference, as the provider's state on a date. It
     creates no revision and never changes a Studio record.

`TracePanel` gained `hook_divengine_trace_panel_actions_alter()` so that the module adds
its action without Traceability depending on it.

## Consequences

### Positive

- Requirement → pull request → verification can be traced and read in Studio, through
  JSON:API and through the MCP `jsonapi_read` tool, under the access rules already in place.
- Another forge needs only a provider plugin; stored references do not change.
- Nothing is mirrored, so nothing goes stale except optional, explicitly observed data
  (#70).

### Negative

- The same external work referenced by several Developments is stored several times, and
  each copy has its own label and notes.
- GitHub serves issues and pull requests under one number sequence, and links of either
  form redirect to the other. Studio stores the kind the link names, so an issue link and
  a pull request link to the same number are two references until #70 can tell them apart.

## Alternatives Rejected

| Alternative | Reason for Rejection |
|---|---|
| A global reference shared by Developments | Its trace links and its very existence would cross Development boundaries; access would depend on the Developments that use it. |
| A link field on artifacts | No identity, no reuse, no typed relation, no history, and no participation in trace rules or baselines. |
| Overloading `source_repository` | It stands for a local clone that Studio indexes; issues and pull requests are not repositories. |
| Mirroring GitHub issues as Studio tasks | Two sources of truth that drift apart; explicitly out of scope in #68. |
| Fetching the page to validate a link | Network access when registering means SSRF exposure and a dependency on the provider being reachable. |

## Related Decisions

- ADR-0006: Distribute Studio as recipes.
- ADR-0007: Unified Process (Developments, artifacts, trace links, baselines).
- ADR-0015: A base recipe that existing sites re-apply on every update.
