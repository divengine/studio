# Testing Configuration

## Prerequisites

- PHPUnit 11 installed via Composer
- PostgreSQL database with credentials configured
- Drupal site properly installed

## Running Tests

Tests are configured via `phpunit.xml` (local overrides) or `phpunit.xml.dist` (distributed template).

### Quick Start

```bash
# Set database credentials via environment variable:
export SIMPLETEST_DB="pgsql://user:password@localhost/database"

# Or edit phpunit.xml directly (gitignored recommended)
# Then run:
./vendor/bin/phpunit
```

### Configuration Files

- `phpunit.xml.dist` - Template with placeholder credentials. Committed to version control.
- `phpunit.xml` - Local overrides with real credentials. **Not committed** (add to `.gitignore`).

Copy the dist file and customize:
```bash
cp phpunit.xml.dist phpunit.xml
# Edit phpunit.xml with your database credentials
```

### Running Specific Tests

```bash
# All kernel tests for custom modules:
./vendor/bin/phpunit --testsuite kernel

# Single test class:
./vendor/bin/phpunit web/modules/custom/divengine_artifact/tests/src/Kernel/UniqueArtifactKeyConstraintTest.php

# Filter by test method:
./vendor/bin/phpunit --filter testDuplicateKeyFails
```

## Test Structure

- Kernel tests only (no browser tests)
- Tests are in `tests/src/Kernel/` under each custom module
- All tests use `#[RunTestsInSeparateProcesses]` attribute for isolation
- Entity schema must be installed in `setUp()` before use

## Writing Tests

```php
#[RunTestsInSeparateProcesses]
class MyConstraintTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'options',
    'divengine_core', 'divengine_artifact',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installConfig('divengine_artifact');
  }

  public function testSomething(): void {
    // Use ::create() followed by ->validate() to test constraints.
  }
}
```

## CI Integration

For CI pipelines, set the `SIMPLETEST_DB` environment variable to the test database DSN.
