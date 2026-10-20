<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\ChangeRequest;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_process\Entity\ProcessActivity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C07: no record is orphaned when a Development is deleted.
 */
#[RunTestsInSeparateProcesses]
class DevelopmentDeletionTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'comment', 'taxonomy', 'node', 'menu_ui', 'file', 'basic_auth', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa', 'divengine_knowledge', 'divengine_articles', 'divengine_source', 'divengine_database', 'divengine_codedocs'];

  protected function setUp(): void {
    parent::setUp();
    // Deletion queries every registered dependent, so all of them need tables.
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $type) {
      if ($type->getBaseTable()) {
        $this->installEntitySchema($type->id());
      }
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['node', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_knowledge']);
    \Drupal\field\Entity\FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    $this->installConfig('divengine_articles');
    $this->createUser();
    $this->setCurrentUser($this->createUser(['administer unified process', 'use unified process']));
  }

  /**
   * One record of each kind that belongs to a Development.
   */
  public static function dependents(): array {
    return [
      'artifact' => ['artifact'],
      'activity' => ['process_activity'],
      'change request' => ['change_request'],
      'baseline' => ['baseline'],
      'QA test plan' => ['qa_test_plan'],
      'knowledge article' => ['node'],
      'source repository' => ['source_repository'],
      'database' => ['schema_database'],
      'documentation set' => ['codedoc_set'],
    ];
  }

  protected function createDependent(string $entity_type_id, Development $development): void {
    $id = $development->id();
    $record = match ($entity_type_id) {
      'artifact' => Artifact::create(['type' => 'risk', 'title' => 'Risk', 'development' => $id]),
      'process_activity' => ProcessActivity::create(['development' => $id, 'name' => 'Activity']),
      'change_request' => ChangeRequest::create(['development' => $id, 'name' => 'Change', 'description' => 'A change.']),
      'baseline' => NULL,
      'qa_test_plan' => \Drupal::entityTypeManager()->getStorage('qa_test_plan')->create(['name' => 'Plan', 'development' => $id]),
      'node' => Node::create(['type' => 'knowledge_article', 'title' => 'Article', 'kb_development' => $id]),
      'source_repository' => \Drupal::entityTypeManager()->getStorage('source_repository')->create(['name' => 'Repo', 'path' => '/opt/repos/x', 'development' => $id]),
      'schema_database' => \Drupal::entityTypeManager()->getStorage('schema_database')->create(['name' => 'DB', 'identifier' => 'db', 'development' => $id]),
      'codedoc_set' => \Drupal::entityTypeManager()->getStorage('codedoc_set')->create(['name' => 'Docs', 'identifier' => 'docs', 'development' => $id]),
    };
    if ($record) {
      $record->save();
    }
    else {
      \Drupal::service('divengine_process.baselines')->take($development, 'Empty baseline');
    }
  }

  public function testEveryIntegrationIsEnumerated(): void {
    $references = \Drupal::service('divengine_process.development_references')->references();
    foreach (array_column(static::dependents(), 0) as $entity_type_id) {
      $this->assertArrayHasKey($entity_type_id, $references, $entity_type_id);
    }
    $this->assertSame('kb_development', $references['node']);
    $this->assertSame('development', $references['source_ref'], 'Child records of an integration count too.');
  }

  #[DataProvider('dependents')]
  public function testDependentKeepsTheDevelopment(string $entity_type_id): void {
    $development = Development::create(['name' => 'With ' . $entity_type_id]);
    $development->save();
    $this->createDependent($entity_type_id, $development);
    $development = Development::load($development->id());

    $this->assertFalse($development->access('delete'), 'The delete operation is not offered.');
    try {
      $development->delete();
      $this->fail("A Development with a $entity_type_id must not be deleted.");
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('cannot be deleted while records belong to it', $e->getMessage());
    }
    $this->assertNotNull(Development::load($development->id()));
    $this->assertNotEmpty(\Drupal::entityQuery('milestone')->accessCheck(FALSE)->condition('development', $development->id())->execute(), 'Its structure stays too.');
  }

  public function testEmptyDevelopmentIsDeletedWithItsStructure(): void {
    $development = Development::create(['name' => 'Nothing yet']);
    $development->save();
    $id = $development->id();
    $this->assertTrue($development->access('delete'));
    $development->delete();
    $this->assertNull(Development::load($id));
    foreach (['development_phase', 'iteration', 'milestone', 'role_assignment'] as $structure) {
      $this->assertEmpty(\Drupal::entityQuery($structure)->accessCheck(FALSE)->condition('development', $id)->execute(), $structure);
    }
  }

}
