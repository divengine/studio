# Security Policy

## Supported versions

Divengine Studio has not published a release yet. Security fixes are made on the `main`
branch. Once releases exist, only the latest release and `main` receive security fixes;
older releases are not patched.

| Version | Security fixes |
|---|---|
| `main` | Yes |
| Latest release (none yet) | Yes, once published |
| Older releases and forks | No |

Drupal core and contributed modules (for example Gin, MCP, JSON:API Schema) are
maintained by their own projects. Vulnerabilities in them must be reported to the
[Drupal Security Team](https://www.drupal.org/drupal-security-team/security-team-procedures/reporting-a-security-issue),
not to this repository. Keep them up to date on your sites (see
[Drupal security advisories](https://www.drupal.org/security)).

## Reporting a vulnerability

**Do not report vulnerabilities in public issues, pull requests, discussions or
comments.**

Report them privately through GitHub's private vulnerability reporting:

1. Open <https://github.com/divengine/studio/security/advisories/new>
   (**Security** tab, **Report a vulnerability**).
2. Describe the vulnerability, the affected component and version or commit, the steps
   to reproduce it and its impact. A minimal proof of concept helps.
3. Do not include real data, credentials or tokens from a production site.

Only the reporter and the maintainer can see a private report.

## What to expect

- The maintainer acknowledges and assesses reports as time allows. There is no
  guaranteed response or fix time.
- The maintainer may ask for more information, and decides on severity, the fix and its
  timing.
- Fixes are developed privately where needed and published with a GitHub Security
  Advisory once a fix is available. Reporters are credited in the advisory unless they
  ask not to be.
- Please keep the report confidential until the advisory is published, and do not
  access, modify or delete data that is not yours while investigating.

## Scope

In scope: the code in this repository, that is the `divengine_*` modules, the Studio
theme, the `divengine_studio_installer` profile, the recipes and the scripts, and the
configuration they install.

Out of scope: vulnerabilities in Drupal core, contributed modules or vendored third-party
libraries themselves (report them upstream), sites that do not follow the web server and
private file system requirements in the [README](README.md#installation), and reports
from automated scanners without a demonstrated impact.
