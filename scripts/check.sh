#!/usr/bin/env bash
# Everything before a commit: the test suite, then a clean install.
# Both use the test database, one after the other.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
"$DIR/test.sh"
"$DIR/check-install.sh"
