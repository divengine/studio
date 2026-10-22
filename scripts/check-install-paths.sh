#!/usr/bin/env bash
# Verifies both supported ways of installing Divengine Studio, each in a
# throw-away site with its own new PostgreSQL database:
#
#   profile             Fresh installation with the divengine_studio_installer
#                       profile (what /core/install.php does), MCP not selected.
#   profile-mcp         The same with the MCP integration selected.
#   recipe-on-standard  A Standard installation, then recipes/divengine_studio_base
#                       applied to it.
#   upgrade             A site set up with the code of an earlier commit
#                       (STUDIO_UPGRADE_FROM, default 869526c, the last one on
#                       Drupal 11.3) the way existing sites were: Standard, then
#                       recipes/divengine_studio and recipes/divengine_studio_mcp.
#                       It is then updated with this checkout as the README
#                       describes: updatedb, cache:rebuild, divengine_studio_base
#                       and divengine_studio_mcp. Not run by default: it needs
#                       a second `composer install` (network or Composer cache).
#
# Each database is named studio_installcheck_<path>_<random>, created for
# the run and dropped afterwards; no existing database is reset or reused.
# Cleanup only removes the databases, site directories and code copies the
# run created itself. The server must run PostgreSQL 16 or later.
# The interactive screens are covered by the profile's Functional tests.
#
# Usage:
#   STUDIO_INSTALLCHECK_SERVER=pgsql://user:password@127.0.0.1:5432 \
#     scripts/check-install-paths.sh [profile|profile-mcp|recipe-on-standard|upgrade ...]
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
php "$ROOT/scripts/test-db-guard.php" --server "$SERVER"
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

# What the current path created, and only that, is removed by cleanup.
SITE="" DB="" OLD=""
cleanup() {
  if [[ -n "$SITE" ]]; then
    chmod -R u+w "$WEB/sites/$SITE" 2>/dev/null || true
    rm -rf -- "${WEB:?}/sites/$SITE" "${ROOT:?}/private/$SITE"
  fi
  if [[ -n "$OLD" ]]; then
    chmod -R u+w "$OLD" 2>/dev/null || true
    rm -rf -- "$OLD"
  fi
  if [[ -n "$DB" ]]; then
    # --force closes connections that an interrupted process still holds.
    pg dropdb --if-exists --force "$DB" || echo "  ! could not drop $DB" >&2
  fi
  SITE="" DB="" OLD=""
}
trap cleanup EXIT

check_path() {
  local path=$1 expect_mcp=0
  local id site db
  id="$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  site="installcheck-$id"
  db="studio_installcheck_${path//-/_}_$id"
  local log="$ROOT/private/installcheck-$path.log"
  local url="pgsql://$DB_USER:$DB_PASS@$DB_HOST:$DB_PORT/$db"
  local name="Install check $id"

  echo "› $path (database $db, site sites/$site)"
  # A name that already exists makes createdb or mkdir fail; it is then not
  # ours and is left alone.
  if [[ -n "$ADMIN" ]]; then pg createdb -O "$DB_USER" "$db"; else pg createdb "$db"; fi || {
    echo "  ✗ could not create the database $db" >&2; return 1; }
  DB="$db"
  PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB" -qtAc "CREATE EXTENSION IF NOT EXISTS pg_trgm" >/dev/null
  mkdir -p "$ROOT/private"
  mkdir "$WEB/sites/$site" "$ROOT/private/$site" || { echo "  ✗ sites/$site already exists" >&2; cleanup; return 1; }
  SITE="$site"
  mkdir "$WEB/sites/$SITE/files"

  # The upgrade path installs with a copy of the earlier code, whose own
  # sites/ directory holds the site until it moves to this checkout.
  local web="$WEB" drush="$DRUSH"
  if [[ "$path" == upgrade ]]; then
    local from="${STUDIO_UPGRADE_FROM:-869526c}"
    OLD="$ROOT/private/installcheck-code-$id"
    mkdir "$OLD"
    echo "  installing the code of $from (composer install)"
    git -C "$ROOT" archive "$from" | tar -x -C "$OLD" \
      && (cd "$OLD" && composer install --no-interaction --no-progress --quiet) >"$log" 2>&1 \
      || { echo "  ✗ could not prepare the code of $from, see $log" >&2; cleanup; return 1; }
    web="$OLD/web" drush="$OLD/vendor/bin/drush"
    mkdir "$web/sites/$SITE"
    mkdir "$web/sites/$SITE/files"
  fi

  cp "$WEB/sites/default/default.settings.php" "$web/sites/$SITE/settings.php"
  cat >> "$web/sites/$SITE/settings.php" <<PHP
\$settings['file_private_path'] = '$ROOT/private/$SITE';
\$settings['config_sync_directory'] = 'sites/$SITE/files/sync';
\$settings['trusted_host_patterns'] = ['^localhost\$'];
PHP

  local site_install=("$drush" -l "$SITE" site:install --db-url="$url" --site-name="$name" --account-name=admin --account-pass="$(head -c12 /dev/urandom | od -An -tx1 | tr -d ' \n')" -y)
  case "$path" in
    profile)
      (cd "$WEB" && "${site_install[@]:0:4}" divengine_studio_installer "${site_install[@]:4}") >"$log" 2>&1 ;;
    profile-mcp)
      expect_mcp=1
      (cd "$WEB" && "${site_install[@]:0:4}" divengine_studio_installer divengine_studio_installer_integrations_form.mcp=1 "${site_install[@]:4}") >"$log" 2>&1 ;;
    recipe-on-standard)
      # Core's recipe command boots sites/default unless told otherwise.
      (cd "$WEB" && "${site_install[@]:0:4}" standard "${site_install[@]:4}" && DRUPAL_DEV_SITE_PATH="sites/$SITE" "$DRUSH" -l "$SITE" recipe ../recipes/divengine_studio_base) >"$log" 2>&1 ;;
    upgrade)
      expect_mcp=1
      (cd "$web" && "${site_install[@]:0:4}" standard "${site_install[@]:4}" \
        && DRUPAL_DEV_SITE_PATH="sites/$SITE" "$drush" -l "$SITE" recipe ../recipes/divengine_studio \
        && DRUPAL_DEV_SITE_PATH="sites/$SITE" "$drush" -l "$SITE" recipe ../recipes/divengine_studio_mcp) >>"$log" 2>&1 \
      && rm -rf -- "${WEB:?}/sites/$SITE" && chmod u+w "$web/sites/$SITE" && mv "$web/sites/$SITE" "$WEB/sites/$SITE" \
      && echo "  updating with this checkout" \
      && (cd "$WEB" && "$DRUSH" -l "$SITE" updatedb -y \
        && "$DRUSH" -l "$SITE" cache:rebuild \
        && DRUPAL_DEV_SITE_PATH="sites/$SITE" "$DRUSH" -l "$SITE" recipe ../recipes/divengine_studio_base \
        && DRUPAL_DEV_SITE_PATH="sites/$SITE" "$DRUSH" -l "$SITE" recipe ../recipes/divengine_studio_mcp) >>"$log" 2>&1 ;;
    *)
      echo "Unknown path $path" >&2; cleanup; return 2 ;;
  esac || { echo "  ✗ installation failed, see $log" >&2; cleanup; return 1; }

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
