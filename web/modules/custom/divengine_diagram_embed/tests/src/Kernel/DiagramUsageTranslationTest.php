<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_diagram_embed\Kernel;

use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers usage per translation of a host.
 */
#[RunTestsInSeparateProcesses]
class DiagramUsageTranslationTest extends DiagramEmbedKernelTestBase {

  protected static $modules = ['language'];

  protected array $hostTypes = ['entity_test_mul'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
  }

  public function testTranslationsAreTrackedSeparately(): void {
    $diagram = $this->createDiagram();
    $usage = $this->container->get('divengine_diagram_embed.usage');
    $host = EntityTestMul::create(['name' => 'Spec', 'body' => ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text']]);
    $host->save();
    $host->addTranslation('es', ['name' => 'Especificación', 'body' => ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text']])->save();
    $this->assertSame(['en', 'es'], array_column($usage->usagesOf((int) $diagram->id()), 'langcode'));

    $host->removeTranslation('es');
    $host->save();
    $this->assertSame(['en'], array_column($usage->usagesOf((int) $diagram->id()), 'langcode'));
  }

}
