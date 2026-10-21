#!/usr/bin/env bash
# Runs the PHPUnit suite of the Studio modules.
#
# Usage: scripts/test.sh [phpunit arguments]
#   scripts/test.sh                                   # everything
#   scripts/test.sh --filter DevelopmentTest          # one test class
# Needs phpunit.xml (copy phpunit.xml.dist and set SIMPLETEST_DB), or
# SIMPLETEST_DB in the environment, which takes precedence. The database must
# be an empty one created for the tests: scripts/test-db-guard.php refuses the
# database of a configured site and any database that holds a Drupal site.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ ! -f "$ROOT/phpunit.xml" ]]; then
  echo "Copy phpunit.xml.dist to phpunit.xml and set SIMPLETEST_DB first." >&2
  exit 2
fi
cd "$ROOT"
DB_URL="${SIMPLETEST_DB:-$(grep -o 'name="SIMPLETEST_DB" value="[^"]*"' phpunit.xml | sed 's/.*value="//; s/"$//')}"
php "$ROOT/scripts/test-db-guard.php" "$DB_URL"
exec vendor/bin/phpunit -c phpunit.xml "${@:-web/modules/custom}"
