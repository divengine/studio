# Handoff Report 002 — Drupal 11 Modernization & Test Hardening

## Summary
Completed Drupal 11 entity annotation→attribute migration, validation constraint cleanup, expanded test coverage, and test configuration hardening.

## Changes Made

### 1. PHP 8 Attributes for Entity Definitions
- **`Artifact.php`** — Migrated `@ContentEntityType` annotation to `#[ContentEntityType]` attribute with all parameters: `show_revision_ui`, `common_reference_target`, `revision_metadata_keys`, `permission_granularity`, `constraints`
- **`ArtifactType.php`** — Migrated `@ConfigEntityType` annotation to `#[ConfigEntityType]` attribute; dropped `admin_ui` (removed in Drupal 11); added `config_export` array
- **`TraceLink.php`** — Migrated `@ContentEntityType` annotation to `#[ContentEntityType]` attribute; added `common_reference_target` parameter

### 2. Validation Constraint Cleanup
- **`UniqueArtifactKeyConstraint.php`** — Removed unused `use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityConstraint` import
- **`divengine_artifact.services.yml`** — Emptied (the redundant `validator.constraint_validator` tagged service was the only entry; `ContainerInjectionInterface` handles DI automatically)
- **`divengine_traceability.services.yml`** — Removed `divengine_traceability.valid_trace_link_constraint_validator` tagged service (redundant with `ContainerInjectionInterface`); kept `divengine_traceability.trace_link_validator` (used by TraceLinkForm)

### 3. Expanded Test Coverage
- **`UniqueArtifactKeyConstraintTest`** — Added 4 new tests:
  - `testDifferentKeysSucceed` — Distinct keys don't conflict
  - `testKeyWithTrailingWhitespaceIsDifferent` — Whitespace is significant in keys
  - `testMultipleUniqueKeysSucceed` — Batch of 5 unique keys
  - `testKeyWithSpecialCharacters` — Underscore in key is valid
- **`ValidTraceLinkConstraintTest`** — Added 7 new tests:
  - `testNonexistentTargetFails` — Complement to existing source test
  - `testNonexistentTargetEntityTypeFails` — Complement to source type test
  - `testBothInvalidSourceAndTarget` — Both source and target nonexistent
  - `testBothEntityTypesInvalid` — Both entity types invalid
  - `testSourceIdZeroFails` — Zero ID edge case
  - `testTargetIdZeroFails` — Zero ID edge case
  - Extracted `createArtifact()` helper
  - Fixed: `assertGreaterThanOrEqual(2)`→`assertGreaterThan(0)` (validator implodes errors into single violation)

- **Totals**: 18 tests, 133 assertions, 0 failures

### 4. Test Configuration Hygiene
- **`phpunit.xml.dist`** — Created with placeholder DB credentials (no hardcoded secrets)
- **`phpunit.xml`** — Preserved with live credentials for local use
- **`docs/development/testing.md`** — Updated with phpunit.xml.dist instructions and environment variable usage

### 5. Form/Constraint Interaction Review
- ArtifactForm: `validateArtifactKey()` performs form-level uniqueness check AND trims whitespace via `submitForm()`. Entity constraint `UniqueArtifactKey` provides API-level enforcement.
- TraceLinkForm: `validateForm()` uses `TraceLinkValidator` for form-level validation, entity constraint `ValidTraceLink` provides API-level enforcement.
- Both forms have dual validation (form + entity constraint). This is intentional: form-level gives immediate UX feedback; entity constraint ensures integrity at API level.

## Verification
- `php -l` on all modified files: no syntax errors
- `drush cr`: cache rebuild successful
- `phpunit`: 18 tests, 133 assertions, 0 failures, 0 errors
- Deprecation warnings (6): all from Drupal/Symfony core (cache.backend.memory, cache.static, Symfony 7.4 Constraint initialization)

## Environment
- Drupal 11.3.12, PHP 8.5.7, PostgreSQL 16, Drush 13.7
- PHPUnit 11.5.55
- All 4 custom modules installed and active

## Files Changed
- `web/modules/custom/divengine_artifact/src/Entity/Artifact.php`
- `web/modules/custom/divengine_artifact/src/Entity/ArtifactType.php`
- `web/modules/custom/divengine_artifact/src/Plugin/Validation/Constraint/UniqueArtifactKeyConstraint.php`
- `web/modules/custom/divengine_artifact/divengine_artifact.services.yml`
- `web/modules/custom/divengine_artifact/tests/src/Kernel/UniqueArtifactKeyConstraintTest.php`
- `web/modules/custom/divengine_traceability/src/Entity/TraceLink.php`
- `web/modules/custom/divengine_traceability/divengine_traceability.services.yml`
- `web/modules/custom/divengine_traceability/tests/src/Kernel/ValidTraceLinkConstraintTest.php`
- `phpunit.xml.dist`
- `phpunit.xml` (updated with dist-compatible format)
- `docs/development/testing.md`

## Next Steps
1. **Views, dashboards, admin UX** — Build listing pages with exposed filters, bulk operations, and administrative overview
2. **PVM / PIM integration** — Connect artifact/trace-link entities to process verification management
3. **CSV import/export** — Bulk artifact creation and trace link mapping
