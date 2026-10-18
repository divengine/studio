#!/usr/bin/env bash
# Runs the PHPUnit suite of the Studio modules.
#
# Usage: scripts/test.sh [phpunit arguments]
#   scripts/test.sh                                   # everything
#   scripts/test.sh --filter DevelopmentTest          # one test class
# Needs phpunit.xml (copy phpunit.xml.dist and set SIMPLETEST_DB).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ ! -f "$ROOT/phpunit.xml" ]]; then
  echo "Copy phpunit.xml.dist to phpunit.xml and set SIMPLETEST_DB first." >&2
  exit 2
fi
cd "$ROOT"
exec vendor/bin/phpunit -c phpunit.xml "${@:-web/modules/custom}"
