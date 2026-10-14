<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_traceability\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Installs the Divengine configuration from scratch and runs its views.
 */
#[RunTestsInSeparateProcesses]
class InstallConfigTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'text',
    'options',
    'field',
    'filter',
    'views',
    'workflows',
    'content_moderation',
    'divengine_core',
    'divengine_artifact',
    'divengine_traceability',
  ];

  public function testFreshInstallProvidesWorkingConfiguration(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('trace_link');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['system', 'views', 'content_moderation', 'divengine_artifact', 'divengine_traceability']);
    \Drupal::service('config.installer')->installOptionalConfig();

    foreach (['divengine_artifacts', 'divengine_trace_links'] as $id) {
      $view = Views::getView($id);
      $this->assertNotNull($view, "View $id is installed.");
      $view->setDisplay('page_1');
      $this->assertSame([], $view->validate(), "View $id is valid.");
      $this->assertTrue($view->execute(), "View $id executes.");
    }
  }

}
