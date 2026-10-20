#!/usr/bin/env bash
# Clean install of Divengine Studio from its recipe, in a throw-away site.
#
# Installs recipes/divengine_studio into the test database, runs a few
# sanity checks and removes everything afterwards. Your real site is not
# touched.
#
# Usage:
#   scripts/check-install.sh            # uses SIMPLETEST_DB from phpunit.xml
#   STUDIO_TEST_DB=pgsql://user:pass@host:5432/db scripts/check-install.sh
#
# The test database is EMPTIED (its public schema is dropped and recreated).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEB="$ROOT/web"
DRUSH="$ROOT/vendor/bin/drush"
SITE="recipecheck"
SITE_DIR="$WEB/sites/$SITE"
PRIVATE_DIR="$ROOT/private/$SITE"

DB_URL="${STUDIO_TEST_DB:-}"
if [[ -z "$DB_URL" && -f "$ROOT/phpunit.xml" ]]; then
  DB_URL="$(grep -o 'name="SIMPLETEST_DB" value="[^"]*"' "$ROOT/phpunit.xml" | sed 's/.*value="//; s/"$//')"
fi
if [[ -z "$DB_URL" ]]; then
  echo "Set STUDIO_TEST_DB or SIMPLETEST_DB in phpunit.xml." >&2
  exit 2
fi
if [[ "$DB_URL" != pgsql://* ]]; then
  echo "Only PostgreSQL test databases are supported (got ${DB_URL%%:*})." >&2
  exit 2
fi

# pgsql://user:password@host:port/database
parse() { python3 -c "import sys,urllib.parse as u; p=u.urlparse(sys.argv[1]); print(getattr(p, sys.argv[2]) or '')" "$DB_URL" "$1"; }
DB_USER="$(parse username)"; DB_PASS="$(parse password)"; DB_HOST="$(parse hostname)"; DB_PORT="$(parse port)"
DB_NAME="$(python3 -c "import sys,urllib.parse as u; print(u.urlparse(sys.argv[1]).path.lstrip('/'))" "$DB_URL")"

reset_db() {
  PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" "$DB_NAME" -qtAc \
    "DROP SCHEMA public CASCADE; CREATE SCHEMA public; CREATE EXTENSION IF NOT EXISTS pg_trgm;" >/dev/null 2>&1
}

cleanup() {
  chmod -R u+w "$SITE_DIR" 2>/dev/null || true
  rm -rf -- "$SITE_DIR" "$PRIVATE_DIR"
  reset_db || true
}
trap cleanup EXIT

echo "› Preparing a throw-away site in sites/$SITE (database $DB_NAME)"
cleanup
mkdir -p "$SITE_DIR/files/sync" "$PRIVATE_DIR"
cp "$WEB/sites/default/default.settings.php" "$SITE_DIR/settings.php"
cat >> "$SITE_DIR/settings.php" <<PHP
\$settings['file_private_path'] = '../private/$SITE';
\$settings['config_sync_directory'] = 'sites/$SITE/files/sync';
PHP
reset_db

echo "› Installing recipes/divengine_studio (a minute or two)"
start=$(date +%s)
(cd "$WEB" && "$DRUSH" -l "$SITE" site:install ../recipes/divengine_studio --db-url="$DB_URL" \
  --account-name=admin --account-pass=admin -y 2>&1 | grep -v "Performed install task" | grep -v '^\s*$') || {
  echo "✗ The installation failed." >&2
  exit 1
}
echo "  installed in $(( $(date +%s) - start ))s"

echo "› Checking the installed site"
(cd "$WEB" && "$DRUSH" -l "$SITE" php:eval '
$checks = [];
$mh = \Drupal::moduleHandler();
foreach (["divengine_core", "divengine_artifact", "divengine_traceability", "divengine_process", "divengine_qa", "divengine_project", "divengine_timesheet", "divengine_thread", "divengine_articles", "divengine_source", "divengine_database", "divengine_codedocs", "basic_auth"] as $module) {
  $checks["module $module"] = $mh->moduleExists($module);
}
$checks["default theme is the Studio theme"] = \Drupal::config("system.theme")->get("default") === "divengine_studio_theme";
foreach (["content_editor", "studio_manager", "project_manager", "qa_tester", "qa_lead", "qa_manager", "database_analyzer", "code_docs_publisher"] as $role) {
  $checks["role $role"] = (bool) \Drupal\user\Entity\Role::load($role);
}
$checks["every record type has a discussion"] = count(\Drupal::service("divengine_thread.manager")->types()) >= 17
  && (bool) \Drupal\field\Entity\FieldConfig::loadByName("node", "knowledge_article", "thread");
$checks["[[CODE]] filter on Basic HTML"] = (bool) \Drupal\filter\Entity\FilterFormat::load("basic_html")->filters("divengine_record_references")->status;
$checks["articles are edited in the Studio theme"] = \Drupal::config("node.settings")->get("use_admin_theme") === FALSE;
$checks["Development cases recommend verification"] = in_array("requirements_verified", array_column(\Drupal::entityTypeManager()->getStorage("development_case")->load("standard")->getLines(), "target"), TRUE);
$checks["database facts tables"] = \Drupal::database()->schema()->tableExists("divengine_db_fk_column");
$checks["code docs facts tables"] = \Drupal::database()->schema()->tableExists("divengine_codedoc_ref") && \Drupal::database()->schema()->tableExists("divengine_codedoc_batch");
$adapters = \Drupal::service("divengine_codedocs.adapters");
$checks["code docs adapters: contract, javadoc, tsdoc"] = $adapters->resolve(["contract" => "divengine.codedocs"])->id() === "contract" && $adapters->resolve([], "javadoc")->id() === "javadoc" && $adapters->resolve([], "tsdoc")->id() === "tsdoc";
try {
  $demo = file_get_contents(\Drupal::moduleHandler()->getModule("divengine_database")->getPath() . "/tests/fixtures/demo_shop_v1.json");
  \Drupal::service("divengine_database.importer")->validate(\Drupal::service("divengine_database.adapters")->toContract($demo));
  $checks["the demo snapshot passes the contract checks"] = TRUE;
}
catch (\Throwable $e) {
  $checks["the demo snapshot passes the contract checks (" . $e->getMessage() . ")"] = FALSE;
}
$failed = 0;
foreach ($checks as $label => $ok) {
  echo ($ok ? "  ✓ " : "  ✗ ") . $label . PHP_EOL;
  $failed += $ok ? 0 : 1;
}
echo "FAILED_CHECKS=$failed" . PHP_EOL;
') | tee /dev/stderr | grep -q '^FAILED_CHECKS=0$' || { echo "✗ Some checks failed." >&2; exit 1; }

echo "✓ Clean install OK (the throw-away site is removed now)."
