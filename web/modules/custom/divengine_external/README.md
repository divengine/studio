# Divengine External References

Work tracked outside Studio (GitHub issues, pull requests and commits, or any web page) as
records of a Development that trace links connect to requirements, decisions and
evidence. Studio stores the reference, never a copy of the external work: GitHub keeps
owning its issues and pull requests (ADR-0018).

## Responsibilities

- The **External reference** entity (`external_reference`), scoped to one Development:
  only people who see the Development see it, and it keeps the Development from being
  deleted.
- Its identity is read from the link when it is registered: provider, host, namespace
  (`owner/repository`), kind and remote ID. It never changes afterwards, and a Development
  has one reference per external work, whatever way the link was written.
- Label and notes are Studio's own; every change is a new revision.
- **Link external work**, in the Traces of every record of a Development: paste a link,
  choose the relationship and direction. The reference is registered or reused, and a
  trace link joins it to the record.
- Pages at `/external/{id}` (open the work, Traces, revisions) and a list at `/external`.
- Read-only through JSON:API and the MCP `jsonapi_read` tool, with the same access rules.

## Providers

Plugins in `Plugin/ExternalProvider` read links. They never contact the network.

| Provider | Hosts | Kinds |
|---|---|---|
| `github` | `github.com`, `www.github.com` | repository, issue, pull request, commit (7 to 40 hex characters), Actions run |
| `link` | any other host | a web page, identified by its normalized link |

Every link must be absolute http or https, name a host and carry no user name or password.
Fragments are dropped; GitHub links also drop their query and any tab after the number
(`/pull/12/files` is `/pull/12`, `/actions/runs/5/job/7` is run 5). Other GitHub pages (wiki, blob, discussions) are refused.
A provider for another forge (a GitLab or Gitea instance) only adds a plugin that claims
its hosts; references do not change.

## Observing GitHub (optional)

With **Read the title and state of public GitHub work** on
(`/admin/divengine/external`, off by default), the page of a GitHub reference offers
**Refresh from GitHub** to people who may edit it. Studio then asks the public REST API:

- only `api.github.com`, anonymously, at a path built from the stored identity;
- with no redirects, a 3 s connect and 5 s total timeout, and answers of at most 1 MiB;
- each reference at most once a minute; while GitHub asks Studio to wait (rate limit),
  nobody asks it.

The answer (title, state such as `open`, `merged`, `draft` or `success`, the date
GitHub gives, or why there is none: not found or not public, rate limited, unavailable) is
stored in `divengine_external_observation`, apart from the reference. Refreshing creates
no revision, and the page presents the answer as GitHub's state on a date, not as a
Studio decision. Nothing is asked in the background, nothing is written to GitHub, and
private repositories are not readable. Reading them would need credentials, which is a
separate design.

## Access

| Operation | Needs |
|---|---|
| See | seeing the Development |
| Register, edit label and notes | "Link external work" and editing the Development |
| Link to a record | the above, "Create trace links", and seeing the record |
| Delete | as editing, and no trace link left |

Trace links follow the usual rules: the writer sees both ends, and both ends belong to the
same Development.

## Writing from code

```php
$references = \Drupal::service('divengine_external.references');
[$reference, $created] = $references->resolve($development_id, 'https://github.com/owner/repo/pull/12', $account);
[$link, $linked] = $references->link($requirement, $reference, 'implements', TRUE, $account, 'Rationale');
```

Both validate as `$account` and throw `ExternalReferenceException` or
`InvalidExternalUrlException` with messages safe to show to that account.

## Dependencies

- divengine_core
- divengine_process
- divengine_traceability
