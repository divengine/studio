<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_artifact\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\State\State;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_core\Sequence;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C06: artifact codes are assigned once and never change afterwards.
 */
#[RunTestsInSeparateProcesses]
class ArtifactKeyStabilityTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'text', 'options', 'field', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig('divengine_artifact');
    $this->setUpCurrentUser([], [], TRUE);
  }

  protected function requirement(string $title = 'Warn before the deadline'): Artifact {
    $artifact = Artifact::create(['type' => 'requirement', 'title' => $title]);
    $artifact->save();
    return $artifact;
  }

  public function testCreationAssignsTheSequence(): void {
    $this->assertSame('REQ-0001', $this->requirement()->getArtifactKey());
    $this->assertSame('REQ-0002', $this->requirement('Second')->getArtifactKey());
    $explicit = Artifact::create(['type' => 'requirement', 'title' => 'Imported', 'artifact_key' => ' LEGACY-7 ']);
    $explicit->save();
    $this->assertSame('LEGACY-7', $explicit->getArtifactKey(), 'An explicit code given at creation is kept, trimmed.');
  }

  public function testDirectSaveCannotChangeTheCode(): void {
    $artifact = $this->requirement();
    $artifact->setArtifactKey('CHANGED-BY-DIRECT-SAVE');
    try {
      $artifact->save();
      $this->fail('A direct save must not change an assigned code.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('REQ-0001', $e->getMessage());
    }
    $this->assertSame('REQ-0001', Artifact::load($artifact->id())->getArtifactKey());

    $artifact = Artifact::load($artifact->id());
    $artifact->setArtifactKey('');
    $this->expectException(EntityStorageException::class);
    $artifact->save();
  }

  public function testValidationReportsTheChangeOnTheCodeField(): void {
    $artifact = $this->requirement();
    $artifact->setArtifactKey('REQ-9999');
    $violations = $artifact->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('artifact_key', $violations->get(0)->getPropertyPath());
    $this->assertStringContainsString('REQ-0001', strip_tags((string) $violations->get(0)->getMessage()));

    $artifact->setArtifactKey(' REQ-0001 ');
    $this->assertCount(0, $artifact->validate(), 'Whitespace around the same code is not a change.');
  }

  public function testOrdinaryEditsAndNewRevisionsKeepTheCode(): void {
    $artifact = $this->requirement();
    $artifact->setTitle('Warn two weeks before the deadline');
    $artifact->setNewRevision();
    $artifact->save();
    $storage = \Drupal::entityTypeManager()->getStorage('artifact');
    $this->assertSame('REQ-0001', $storage->loadUnchanged($artifact->id())->getArtifactKey());
    $this->assertSame(2, (int) \Drupal::database()->select('artifact_revision', 'r')->condition('id', $artifact->id())->countQuery()->execute()->fetchField());
  }

  public function testRevertKeepsTheCurrentCode(): void {
    $artifact = $this->requirement();
    $first = (int) $artifact->getRevisionId();
    $artifact->setTitle('Second version');
    $artifact->setNewRevision();
    $artifact->save();
    // A revision written before codes were permanent may carry another code.
    \Drupal::database()->update('artifact_revision')->fields(['artifact_key' => 'OLD-1'])->condition('revision_id', $first)->execute();
    \Drupal::entityTypeManager()->getStorage('artifact')->resetCache();

    $storage = \Drupal::entityTypeManager()->getStorage('artifact');
    $old = $storage->loadRevision($first);
    $this->assertSame('OLD-1', $old->getArtifactKey());
    // What RevisionRevertForm does.
    $old->setNewRevision();
    $old->isDefaultRevision(TRUE);
    $old->save();
    $reverted = $storage->loadUnchanged($artifact->id());
    $this->assertSame('Warn before the deadline', $reverted->getTitle());
    $this->assertSame('REQ-0001', $reverted->getArtifactKey(), 'A revert does not renumber the artifact.');
  }

  public function testConcurrentGenerationDoesNotDuplicate(): void {
    $container = \Drupal::getContainer();
    // Two processes, each with its own state cache primed before the other writes.
    $states = [];
    foreach ([0, 1] as $i) {
      $states[$i] = new State($container->get('keyvalue'), $container->get('cache.bootstrap'), $container->get('lock'));
      $states[$i]->get('divengine_core.sequence.REQ');
    }
    $first = new Sequence($states[0], $container->get('lock'));
    $second = new Sequence($states[1], $container->get('lock'));
    $codes = [$first->next('REQ'), $second->next('REQ'), $first->next('REQ'), $second->next('REQ')];
    $this->assertSame(['REQ-0001', 'REQ-0002', 'REQ-0003', 'REQ-0004'], $codes);
  }

}
