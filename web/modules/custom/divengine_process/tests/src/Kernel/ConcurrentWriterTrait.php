<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Real database locks and a second connection acting as another request.
 *
 * KernelTestBase replaces locks with a null backend, under which tests of
 * serialization would pass vacuously.
 */
trait ConcurrentWriterTrait {

  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', ScriptedLockBackend::class)
      ->addArgument(new Reference('database'));
  }

  protected function tearDown(): void {
    // Release before the test database is removed, not at shutdown.
    \Drupal::lock()->releaseAll();
    parent::tearDown();
  }

  /**
   * Saves an entity through its own database connection and transaction.
   *
   * What it stores is committed independently of the transaction of the
   * request being tested, as another request's write would be.
   */
  protected function saveAsAnotherRequest(EntityInterface $entity): void {
    if (!Database::getConnectionInfo('another_request')) {
      Database::addConnectionInfo('another_request', 'default', Database::getConnectionInfo('default')['default']);
    }
    $database = $this->container->get('database');
    $this->container->set('database', Database::getConnection('default', 'another_request'));
    try {
      $type = \Drupal::entityTypeManager()->getDefinition($entity->getEntityTypeId());
      $storage = $type->getStorageClass()::createInstance($this->container, $type);
    }
    finally {
      $this->container->set('database', $database);
    }
    $storage->save($entity);
  }

  /**
   * Asserts that no request holds a lock any more.
   */
  protected function assertLockReleased(string $name): void {
    $this->assertTrue(\Drupal::lock()->lockMayBeAvailable($name), "Lock $name is released.");
  }

}
