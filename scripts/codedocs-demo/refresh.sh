#!/usr/bin/env bash
# Create the examples once, then extract and import their current Git commits.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STUDIO_ROOT="${STUDIO_ROOT:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
export CODEDOCS_DEMO_ROOT="${CODEDOCS_DEMO_ROOT:-$HOME/workspace/codedocs-demo}"
CODEINTEL_TS_EXTRACTOR="${CODEINTEL_TS_EXTRACTOR:-$HOME/workspace/dev/tsdoc/extractor/extract.mjs}"
if ! command -v node >/dev/null && [[ -s "$HOME/.nvm/nvm.sh" ]]; then
  source "$HOME/.nvm/nvm.sh"
fi
[[ -f "$CODEINTEL_TS_EXTRACTOR" ]] || { echo "Set CODEINTEL_TS_EXTRACTOR to CodeIntel's tsdoc/extractor/extract.mjs" >&2; exit 1; }
mkdir -p "$CODEDOCS_DEMO_ROOT/output"
for project in php-shop ts-shop; do
  target="$CODEDOCS_DEMO_ROOT/$project"
  if [[ ! -d "$target" ]]; then
    cp -R "$SCRIPT_DIR/$project" "$target"
    git -C "$target" init -b main
    git -C "$target" config user.name 'Studio Demo'
    git -C "$target" config user.email 'studio-demo@example.invalid'
    if [[ "$project" == ts-shop ]]; then (cd "$target" && npm install --no-audit --no-fund); fi
    git -C "$target" add .
    git -C "$target" commit -m 'Create documented shop example'
  fi
  if [[ -n "$(git -C "$target" status --porcelain)" ]]; then
    echo "Commit your changes in $target before publishing; snapshots must match their Git commit." >&2
    exit 1
  fi
done
php "$SCRIPT_DIR/extract-php.php" "$CODEDOCS_DEMO_ROOT/php-shop" demo-shop-php > "$CODEDOCS_DEMO_ROOT/output/php.json"
node "$CODEINTEL_TS_EXTRACTOR" --root "$CODEDOCS_DEMO_ROOT/ts-shop" --out "$CODEDOCS_DEMO_ROOT/output/ts.json" --project demo-shop-ts --commit "$(git -C "$CODEDOCS_DEMO_ROOT/ts-shop" rev-parse HEAD)" --branch main
cd "$STUDIO_ROOT"
vendor/bin/drush php:script "$SCRIPT_DIR/register-import.php"
vendor/bin/drush cache:rebuild
