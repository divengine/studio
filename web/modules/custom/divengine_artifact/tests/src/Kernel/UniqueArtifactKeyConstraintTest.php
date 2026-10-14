<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_artifact\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\divengine_artifact\Entity\Artifact;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
class UniqueArtifactKeyConstraintTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'text',
    'options',
    'field',
    'divengine_core',
    'divengine_artifact',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('artifact_type');
    $this->installConfig('divengine_artifact');
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

  public function testEmptyKeyIsRequired(): void {
    $artifact = Artifact::create([
      'title' => 'No Key',
      'artifact_key' => '',
      'type' => 'requirement',
    ]);
    $violations = $artifact->validate();
    $this->assertGreaterThan(0, count($violations), 'Empty artifact key should produce violations.');
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

  public function testKeyWithTrailingWhitespaceIsDifferent(): void {
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
    $this->assertCount(0, $violations, 'Key with trailing whitespace should not be considered a duplicate of the same key without whitespace.');
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
