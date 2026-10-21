<!--
Thank you for contributing. Please read CONTRIBUTING.md first.
Security fixes for undisclosed vulnerabilities must not be opened as public pull
requests: follow SECURITY.md. Remove secrets, database URLs and personal data from logs.
-->

## Linked issue

<!-- "Closes #123" or "Refs #123". Substantial changes need an issue agreed with the maintainer first. -->

## Scope

<!-- What this pull request changes, and what it deliberately leaves out. -->

## User-visible changes

<!-- Screens, behavior, permissions or messages that change for site users or administrators. Write "None" if there are none. -->

## Testing evidence

<!-- The commands you ran and their results (tests, assertions, failures). Say what you could not run and why. -->

- [ ] Kernel tests: `vendor/bin/phpunit -c phpunit.xml --testsuite kernel`
- [ ] Functional tests, if HTTP, forms or the installer are affected
- [ ] `scripts/check-install-paths.sh`, if recipes, the profile or installed configuration are affected
- [ ] JavaScript tests, if editor or diagram JavaScript is affected
- [ ] Manual check in a browser, if the UI is affected

## Security, data and access impact

<!-- Access checks, permissions, private records, file access, input handling, stored history (revisions, baselines), data changes on existing sites. Write "None known" if there is none. -->

## Documentation

- [ ] README, module README, ADR or `docs/` updated where needed
- [ ] `CHANGELOG.md` updated under **Unreleased** for user-visible changes
- [ ] No documentation change needed

## Install and upgrade implications

<!-- New dependencies, configuration, update hooks, recipe changes. What does an existing site need: `drush updatedb`, re-applying `recipes/divengine_studio_base`, `drush cache:rebuild`, manual steps? Write "None" if nothing. -->

## Checklist

- [ ] The change is focused on the linked issue.
- [ ] The contribution is my own work or compatible with GPL-3.0-or-later, with third-party licenses kept.
- [ ] I understand that the maintainer decides whether and when this is merged.
