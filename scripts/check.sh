#!/usr/bin/env bash
# Everything before a commit: the test suite, then every installation path.
#
# The tests use SIMPLETEST_DB (scripts/test.sh). The installation paths
# create and drop their own databases on the server given by
# STUDIO_INSTALLCHECK_SERVER, or by default on the server of SIMPLETEST_DB
# (scripts/check-install-paths.sh). No existing database is reset.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
"$ROOT/scripts/test.sh"
if [[ -z "${STUDIO_INSTALLCHECK_SERVER:-}" ]]; then
  DB_URL="${SIMPLETEST_DB:-$(grep -o 'name="SIMPLETEST_DB" value="[^"]*"' "$ROOT/phpunit.xml" | sed 's/.*value="//; s/"$//')}"
  export STUDIO_INSTALLCHECK_SERVER="${DB_URL%/*}"
fi
"$ROOT/scripts/check-install-paths.sh"
