<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\divengine_process\Entity\Development;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C07: integrations that are not installed are ignored, not required.
 */
#[RunTestsInSeparateProcesses]
class DevelopmentReferencesTest extends KernelTestBase {

  use BaselineFixtureTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  public function testOnlyInstalledTypesAreEnumerated(): void {
    $this->installBaselineFixture();
    $references = \Drupal::service('divengine_process.development_references')->references();
    $this->assertArrayHasKey('artifact', $references);
    foreach (['qa_test_plan', 'source_repository', 'schema_database', 'codedoc_set', 'node'] as $absent) {
      $this->assertArrayNotHasKey($absent, $references, $absent);
    }
    $development = Development::create(['name' => 'Alone']);
    $development->save();
    $this->assertSame([], \Drupal::service('divengine_process.development_references')->dependents($development->id()));
    $development->delete();
    $this->assertNull(Development::load($development->id()));
  }

}
