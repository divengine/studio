#!/usr/bin/env bash
# Clean installation of Divengine Studio with the installation profile, in a
# throw-away site and a database created for the run.
#
# Kept for existing habits: it runs the "profile" path of
# scripts/check-install-paths.sh, which never resets or reuses an existing
# database. The server comes from STUDIO_INSTALLCHECK_SERVER, or from the
# server part of SIMPLETEST_DB in phpunit.xml.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ -z "${STUDIO_INSTALLCHECK_SERVER:-}" ]]; then
  if [[ -n "${STUDIO_TEST_DB:-}" ]]; then
    echo "STUDIO_TEST_DB is no longer used: the check creates its own database. Set STUDIO_INSTALLCHECK_SERVER=pgsql://user:password@host:port." >&2
    exit 2
  fi
  DB_URL="${SIMPLETEST_DB:-$( [[ -f "$ROOT/phpunit.xml" ]] && grep -o 'name="SIMPLETEST_DB" value="[^"]*"' "$ROOT/phpunit.xml" | sed 's/.*value="//; s/"$//' || true)}"
  if [[ -z "$DB_URL" ]]; then
    echo "Set STUDIO_INSTALLCHECK_SERVER, or SIMPLETEST_DB in phpunit.xml." >&2
    exit 2
  fi
  export STUDIO_INSTALLCHECK_SERVER="${DB_URL%/*}"
fi
exec "$ROOT/scripts/check-install-paths.sh" profile
