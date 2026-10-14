<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_artifact\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\divengine_artifact\ArtifactPermissions;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_artifact\Entity\ArtifactType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers artifact revisions, revision routes and per-type permissions.
 */
#[RunTestsInSeparateProcesses]
class ArtifactRevisionAccessTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'text',
    'options',
    'field',
    'workflows',
    'content_moderation',
    'divengine_core',
    'divengine_artifact',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig('divengine_artifact');
    // Take uid 1, which bypasses every access check.
    $this->createUser();
  }

  public function testTypesCreateRevisionsByDefault(): void {
    $this->assertTrue(ArtifactType::load('requirement')->shouldCreateNewRevision());
  }

  public function testRevisionsKeepHistoryAndLogMessages(): void {
    $artifact = Artifact::create(['title' => 'Login', 'artifact_key' => 'REQ-1', 'type' => 'requirement']);
    $artifact->save();
    $first_revision = $artifact->getRevisionId();

    $artifact->setTitle('Login with MFA');
    $artifact->setNewRevision();
    $artifact->setRevisionLogMessage('Add MFA');
    $artifact->save();

    $storage = \Drupal::entityTypeManager()->getStorage('artifact');
    $revision_ids = $storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $artifact->id())->execute();
    $this->assertCount(2, $revision_ids);
    $this->assertSame('Login', $storage->loadRevision($first_revision)->getTitle());
    $this->assertSame('Add MFA', $storage->load($artifact->id())->getRevisionLogMessage());
  }

  public function testRevisionRoutesExist(): void {
    $routes = \Drupal::service('router.route_provider');
    foreach (['version_history', 'revision', 'revision_revert_form', 'revision_delete_form'] as $suffix) {
      $this->assertNotNull($routes->getRouteByName("entity.artifact.$suffix"));
    }
  }

  public function testPermissionsAreGeneratedPerType(): void {
    $permissions = ArtifactPermissions::create(\Drupal::getContainer())->artifactTypePermissions();
    foreach (['create', 'edit', 'delete'] as $operation) {
      $this->assertArrayHasKey("$operation requirement artifact", $permissions);
    }
    $this->assertArrayHasKey('revert use_case artifact revisions', $permissions);
  }

  public function testBundlePermissionsOnlyCoverTheirType(): void {
    $requirement = Artifact::create(['title' => 'R', 'artifact_key' => 'REQ-1', 'type' => 'requirement']);
    $requirement->save();
    $risk = Artifact::create(['title' => 'K', 'artifact_key' => 'RSK-1', 'type' => 'risk']);
    $risk->save();

    $analyst = $this->createUser([
      'edit requirement artifact',
      'create requirement artifact',
      'view requirement artifact revisions',
      // Moderated artifacts can only be edited through a transition.
      'use artifact_lifecycle transition create_new_draft',
    ]);
    $this->assertTrue($requirement->access('update', $analyst));
    $this->assertFalse($risk->access('update', $analyst));
    $this->assertTrue($requirement->access('view all revisions', $analyst));
    $this->assertFalse($requirement->access('revert', $analyst));

    $handler = \Drupal::entityTypeManager()->getAccessControlHandler('artifact');
    $this->assertTrue($handler->createAccess('requirement', $analyst));
    $this->assertFalse($handler->createAccess('risk', $analyst));

    $editor = $this->createUser(['edit artifact', 'use artifact_lifecycle transition create_new_draft']);
    $this->assertTrue($risk->access('update', $editor), 'The type-wide permission covers every type.');
  }

}
