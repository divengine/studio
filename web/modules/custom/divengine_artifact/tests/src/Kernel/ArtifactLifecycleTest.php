<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_artifact\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\workflows\Entity\Workflow;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the artifact lifecycle workflow.
 */
#[RunTestsInSeparateProcesses]
class ArtifactLifecycleTest extends KernelTestBase {

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
    $this->installConfig(['content_moderation', 'divengine_artifact']);
  }

  public function testWorkflowCoversEveryArtifactType(): void {
    $bundles = Workflow::load('artifact_lifecycle')->getTypePlugin()->getBundlesForEntityType('artifact');
    sort($bundles);
    $this->assertSame([
      'decision', 'evidence', 'knowledge_reference', 'method_fragment', 'requirement',
      'risk', 'standard_reference', 'test_case', 'use_case',
    ], $bundles);
  }

  public function testNewArtifactsStartAsUnpublishedDrafts(): void {
    $artifact = Artifact::create(['title' => 'Login', 'artifact_key' => 'REQ-1', 'type' => 'requirement']);
    $artifact->save();
    $this->assertSame('draft', $artifact->get('moderation_state')->value);
    $this->assertFalse($artifact->isPublished());
  }

  public function testReviewApproveAndDeprecate(): void {
    $artifact = Artifact::create(['title' => 'Login', 'artifact_key' => 'REQ-1', 'type' => 'requirement']);
    $artifact->save();

    $this->moveTo($artifact, 'review');
    $this->assertFalse($artifact->isPublished());

    $this->moveTo($artifact, 'published');
    $this->assertTrue($this->reload($artifact)->isPublished(), 'Approved artifacts are published.');

    // A new draft of an approved artifact does not replace the approved text.
    $artifact->setTitle('Login with MFA');
    $this->moveTo($artifact, 'draft');
    $default = $this->reload($artifact);
    $this->assertSame('Login', $default->getTitle());
    $this->assertSame('published', $default->get('moderation_state')->value);

    $this->moveTo($default, 'archived');
    $this->assertFalse($this->reload($artifact)->isPublished(), 'Deprecated artifacts are unpublished.');
  }

  public function testInvalidTransitionIsRejected(): void {
    $artifact = Artifact::create(['title' => 'Login', 'artifact_key' => 'REQ-1', 'type' => 'requirement']);
    $artifact->save();
    // Approval must go through review.
    $artifact->set('moderation_state', 'published');
    $this->assertGreaterThan(0, $artifact->validate()->count());
  }

  protected function moveTo(Artifact $artifact, string $state): void {
    $artifact->set('moderation_state', $state);
    $artifact->setNewRevision();
    $artifact->save();
  }

  protected function reload(Artifact $artifact): Artifact {
    $storage = \Drupal::entityTypeManager()->getStorage('artifact');
    $storage->resetCache([$artifact->id()]);
    return $storage->load($artifact->id());
  }

}
