<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\divengine_modeler\Entity\Diagram;
use Drupal\divengine_modeler\Entity\DiagramInterface;

/**
 * Installs the modeler platform with the test "sketch" notation.
 */
abstract class ModelerKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'workflows',
    'content_moderation',
    'views',
    'divengine_core',
    'divengine_modeler',
    'divengine_modeler_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('diagram');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('divengine_modeler', ['diagram_element_link']);
    $this->installConfig(['content_moderation', 'divengine_modeler', 'divengine_modeler_test']);
    // Moderation checks transitions against the current user.
    $this->setUpCurrentUser([], [], TRUE);
  }

  protected function createDiagram(array $values = []): DiagramInterface {
    $diagram = Diagram::create($values + ['type' => 'sketch', 'label' => 'Sketch']);
    $diagram->save();
    return $diagram;
  }

  protected function reload(DiagramInterface $diagram): DiagramInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('diagram');
    $storage->resetCache();
    return $storage->load($diagram->id());
  }

  protected function latest(DiagramInterface $diagram): DiagramInterface {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('diagram');
    $storage->resetCache();
    return $storage->loadRevision($storage->getLatestRevisionId($diagram->id()));
  }

  protected function svg(string $body = '<g data-divengine-element="a"><rect width="10" height="10"/></g>'): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' . $body . '</svg>';
  }

}
