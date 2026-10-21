#!/usr/bin/env bash
# Verifies both supported ways of installing Divengine Studio, each in a
# throw-away site with its own new PostgreSQL database:
#
#   profile             Fresh installation with the divengine_studio_installer
#                       profile (what /core/install.php does), MCP not selected.
#   profile-mcp         The same with the MCP integration selected.
#   recipe-on-standard  A Standard installation, then recipes/divengine_studio
#                       applied to it.
#
# Each database is named studio_installcheck_<path>_<random>, created for
# the run and dropped afterwards; no existing database is reset or reused.
# The interactive screens are covered by the profile's Functional tests.
#
# Usage:
#   STUDIO_INSTALLCHECK_SERVER=pgsql://user:password@127.0.0.1:5432 \
#     scripts/check-install-paths.sh [profile|profile-mcp|recipe-on-standard ...]
#
# The user must be allowed to create databases. When it is not, give a
# command prefix that runs createdb/dropdb as a role that is, for example
#   STUDIO_INSTALLCHECK_PG_ADMIN="sudo -n -u postgres"
# The databases are then owned by the user of STUDIO_INSTALLCHECK_SERVER.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEB="$ROOT/web"
DRUSH="$ROOT/vendor/bin/drush"
SERVER="${STUDIO_INSTALLCHECK_SERVER:-}"
ADMIN="${STUDIO_INSTALLCHECK_PG_ADMIN:-}"
PATHS=("$@")
[[ ${#PATHS[@]} -eq 0 ]] && PATHS=(profile profile-mcp recipe-on-standard)

if [[ "$SERVER" != pgsql://* ]]; then
  echo "Set STUDIO_INSTALLCHECK_SERVER=pgsql://user:password@host:port (PostgreSQL only)." >&2
  exit 2
fi
parse() { python3 -c "import sys,urllib.parse as u; p=u.urlparse(sys.argv[1]); print(getattr(p, sys.argv[2]) or '')" "$SERVER" "$1"; }
DB_USER="$(parse username)"; DB_PASS="$(parse password)"; DB_HOST="$(parse hostname)"; DB_PORT="$(parse port)"; DB_PORT="${DB_PORT:-5432}"

pg() {
  local tool=$1; shift
  if [[ -n "$ADMIN" ]]; then
    $ADMIN "$tool" "$@"
  else
    PGPASSWORD="$DB_PASS" "$tool" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$@"
  fi
}

SITE="" DB=""
cleanup() {
  if [[ -n "$SITE" ]]; then
    chmod -R u+w "$WEB/sites/$SITE" 2>/dev/null || true
    rm -rf -- "${WEB:?}/sites/$SITE" "${ROOT:?}/private/$SITE"
  fi
  if [[ -n "$DB" ]]; then
    pg dropdb --if-exists "$DB" || echo "  ! could not drop $DB" >&2
  fi
  SITE="" DB=""
}
trap cleanup EXIT

check_path() {
  local path=$1 expect_mcp=0
  local id
  id="$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  SITE="installcheck-$id"
  DB="studio_installcheck_${path//-/_}_$id"
  local log="$ROOT/private/installcheck-$path.log"
  local url="pgsql://$DB_USER:$DB_PASS@$DB_HOST:$DB_PORT/$DB"
  local name="Install check $id"

  echo "› $path (database $DB, site sites/$SITE)"
  if [[ -n "$ADMIN" ]]; then pg createdb -O "$DB_USER" "$DB"; else pg createdb "$DB"; fi
  PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB" -qtAc "CREATE EXTENSION IF NOT EXISTS pg_trgm" >/dev/null

  mkdir -p "$WEB/sites/$SITE/files" "$ROOT/private/$SITE"
  cp "$WEB/sites/default/default.settings.php" "$WEB/sites/$SITE/settings.php"
  cat >> "$WEB/sites/$SITE/settings.php" <<PHP
\$settings['file_private_path'] = '../private/$SITE';
\$settings['config_sync_directory'] = 'sites/$SITE/files/sync';
\$settings['trusted_host_patterns'] = ['^localhost\$'];
PHP

  local site_install=("$DRUSH" -l "$SITE" site:install --db-url="$url" --site-name="$name" --account-name=admin --account-pass="$(head -c12 /dev/urandom | od -An -tx1 | tr -d ' \n')" -y)
  case "$path" in
    profile)
      (cd "$WEB" && "${site_install[@]:0:4}" divengine_studio_installer "${site_install[@]:4}") >"$log" 2>&1 ;;
    profile-mcp)
      expect_mcp=1
      (cd "$WEB" && "${site_install[@]:0:4}" divengine_studio_installer divengine_studio_installer_integrations_form.mcp=1 "${site_install[@]:4}") >"$log" 2>&1 ;;
    recipe-on-standard)
      # Core's recipe command boots sites/default unless told otherwise.
      (cd "$WEB" && "${site_install[@]:0:4}" standard "${site_install[@]:4}" && DRUPAL_DEV_SITE_PATH="sites/$SITE" "$DRUSH" -l "$SITE" recipe ../recipes/divengine_studio) >"$log" 2>&1 ;;
    *)
      echo "Unknown path $path" >&2; return 2 ;;
  esac || { echo "  ✗ installation failed, see $log" >&2; return 1; }

  local failed=0
  (cd "$WEB" && "$DRUSH" -l "$SITE" php:script "$ROOT/scripts/install-check.php" -- "$expect_mcp" "$name") | tee -a "$log" | grep -q '^FAILED_CHECKS=0$' || failed=1
  grep '^  [✓✗]' "$log"
  local errors
  errors="$(cd "$WEB" && "$DRUSH" -l "$SITE" core:requirements --severity=2 --format=json 2>/dev/null || true)"
  if [[ -z "$errors" || "$errors" == "[]" ]]; then
    echo "  ✓ drush core:requirements reports no errors"
  else
    echo "  ✗ drush core:requirements reports errors: $errors"
    failed=1
  fi
  cleanup
  return $failed
}

status=0
for path in "${PATHS[@]}"; do
  check_path "$path" || status=1
done
if [[ $status -eq 0 ]]; then
  echo "✓ Installation paths OK: ${PATHS[*]} (throw-away sites and databases removed)."
else
  echo "✗ Some installation checks failed." >&2
fi
exit $status
