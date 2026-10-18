<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_artifact\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\divengine_artifact\Entity\Artifact;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
class UniqueArtifactKeyConstraintTest extends KernelTestBase {

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
    $this->installEntitySchema('artifact_type');
    $this->installConfig('divengine_artifact');
    // Moderated saves validate the transition against the current user.
    $this->setUpCurrentUser([], [], TRUE);
  }

  public function testUniqueKeySucceeds(): void {
    $artifact = Artifact::create([
      'title' => 'Test Artifact',
      'artifact_key' => 'REQ-001',
      'type' => 'requirement',
    ]);
    $violations = $artifact->validate();
    $this->assertCount(0, $violations, 'No violations for unique artifact key.');
    $artifact->save();
  }

  public function testDuplicateKeyFails(): void {
    Artifact::create([
      'title' => 'First Artifact',
      'artifact_key' => 'REQ-001',
      'type' => 'requirement',
    ])->save();

    $artifact2 = Artifact::create([
      'title' => 'Second Artifact',
      'artifact_key' => 'REQ-001',
      'type' => 'requirement',
    ]);
    $violations = $artifact2->validate();
    $this->assertCount(1, $violations, 'Duplicate artifact key should produce a violation.');
    $this->assertStringContainsString('REQ-001', (string) $violations->get(0)->getMessage());
  }

  public function testSameKeyOnUpdateSucceeds(): void {
    $artifact = Artifact::create([
      'title' => 'Original',
      'artifact_key' => 'REQ-001',
      'type' => 'requirement',
    ]);
    $artifact->save();

    $artifact->setTitle('Updated');
    $violations = $artifact->validate();
    $this->assertCount(0, $violations, 'Updating an artifact with its own key should not produce a violation.');
  }

  public function testEmptyKeyIsAssignedFromTheTypePrefix(): void {
    $artifact = Artifact::create(['title' => 'No Key', 'artifact_key' => '', 'type' => 'requirement']);
    $this->assertCount(0, $artifact->validate());
    $artifact->save();
    $this->assertSame('REQ-0001', $artifact->getArtifactKey());
    $second = Artifact::create(['title' => 'Next', 'type' => 'requirement']);
    $second->save();
    $this->assertSame('REQ-0002', $second->getArtifactKey());
  }

  public function testDifferentKeysSucceed(): void {
    Artifact::create([
      'title' => 'First Key',
      'artifact_key' => 'REQ-001',
      'type' => 'requirement',
    ])->save();

    $artifact2 = Artifact::create([
      'title' => 'Different Key',
      'artifact_key' => 'REQ-002',
      'type' => 'requirement',
    ]);
    $violations = $artifact2->validate();
    $this->assertCount(0, $violations, 'Different keys should not conflict.');
  }

  public function testKeyWithSurroundingWhitespaceIsDuplicate(): void {
    Artifact::create([
      'title' => 'Without Space',
      'artifact_key' => 'REQ-001',
      'type' => 'requirement',
    ])->save();

    $artifact2 = Artifact::create([
      'title' => 'With Trailing Space',
      'artifact_key' => 'REQ-001 ',
      'type' => 'requirement',
    ]);
    $violations = $artifact2->validate();
    $this->assertCount(1, $violations, 'Surrounding whitespace must not make a key distinct.');
    $this->assertSame('artifact_key', $violations->get(0)->getPropertyPath());
  }

  public function testKeyIsTrimmedOnSave(): void {
    $artifact = Artifact::create([
      'title' => 'Padded',
      'artifact_key' => '  REQ-009  ',
      'type' => 'requirement',
    ]);
    $artifact->save();
    $this->assertSame('REQ-009', Artifact::load($artifact->id())->getArtifactKey());
  }

  public function testMultipleUniqueKeysSucceed(): void {
    for ($i = 1; $i <= 5; $i++) {
      $artifact = Artifact::create([
        'title' => "Artifact $i",
        'artifact_key' => "KEY-$i",
        'type' => 'requirement',
      ]);
      $violations = $artifact->validate();
      $this->assertCount(0, $violations, "No violations for unique key KEY-$i.");
      $artifact->save();
    }
  }

  public function testKeyWithSpecialCharacters(): void {
    $artifact = Artifact::create([
      'title' => 'Special Key',
      'artifact_key' => 'REQ-001_ABC',
      'type' => 'requirement',
    ]);
    $violations = $artifact->validate();
    $this->assertCount(0, $violations, 'Key with underscore should be valid.');
    $artifact->save();
  }

}
