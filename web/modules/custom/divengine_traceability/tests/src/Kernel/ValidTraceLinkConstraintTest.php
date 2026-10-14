<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_traceability\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_traceability\Entity\TraceLink;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
class ValidTraceLinkConstraintTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'text',
    'options',
    'field',
    'divengine_core',
    'divengine_artifact',
    'divengine_knowledge',
    'divengine_traceability',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('artifact_type');
    $this->installEntitySchema('trace_link');
    $this->installConfig('divengine_artifact');
  }

  public function testValidTraceLinkSucceeds(): void {
    $source = $this->createArtifact('REQ-001', 'requirement');
    $target = $this->createArtifact('TC-001', 'test_case');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => $source->id(),
      'target_entity_type' => 'artifact',
      'target_entity_id' => $target->id(),
      'link_type' => 'validates',
    ]);
    $violations = $link->validate();
    $this->assertCount(0, $violations, 'Valid trace link should have no violations.');
    $link->save();
  }

  public function testNonexistentSourceFails(): void {
    $target = $this->createArtifact('TC-001', 'test_case');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => 99999,
      'target_entity_type' => 'artifact',
      'target_entity_id' => $target->id(),
      'link_type' => 'validates',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Nonexistent source should produce a violation.');
  }

  public function testNonexistentTargetFails(): void {
    $source = $this->createArtifact('REQ-001', 'requirement');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => $source->id(),
      'target_entity_type' => 'artifact',
      'target_entity_id' => 99999,
      'link_type' => 'validates',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Nonexistent target should produce a violation.');
  }

  public function testSameSourceAndTargetFails(): void {
    $artifact = $this->createArtifact('SAME-001', 'requirement');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => $artifact->id(),
      'target_entity_type' => 'artifact',
      'target_entity_id' => $artifact->id(),
      'link_type' => 'references',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Same source and target should produce a violation.');
  }

  public function testNonexistentSourceEntityTypeFails(): void {
    $source = $this->createArtifact('SRC-001', 'requirement');

    $link = TraceLink::create([
      'source_entity_type' => 'invalid_type',
      'source_entity_id' => $source->id(),
      'target_entity_type' => 'artifact',
      'target_entity_id' => $source->id(),
      'link_type' => 'references',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Invalid source entity type should produce a violation.');
  }

  public function testNonexistentTargetEntityTypeFails(): void {
    $source = $this->createArtifact('SRC-001', 'requirement');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => $source->id(),
      'target_entity_type' => 'nonexistent_type',
      'target_entity_id' => $source->id(),
      'link_type' => 'references',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Invalid target entity type should produce a violation.');
  }

  public function testBothInvalidSourceAndTarget(): void {
    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => 99998,
      'target_entity_type' => 'artifact',
      'target_entity_id' => 99999,
      'link_type' => 'validates',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Both invalid source and target should produce at least one violation.');
  }

  public function testBothEntityTypesInvalid(): void {
    $link = TraceLink::create([
      'source_entity_type' => 'unknown_source',
      'source_entity_id' => 1,
      'target_entity_type' => 'unknown_target',
      'target_entity_id' => 1,
      'link_type' => 'references',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Both invalid entity types should produce at least one violation.');
  }

  public function testSourceIdZeroFails(): void {
    $target = $this->createArtifact('TC-001', 'test_case');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => 0,
      'target_entity_type' => 'artifact',
      'target_entity_id' => $target->id(),
      'link_type' => 'validates',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Source ID of zero should produce a violation.');
  }

  public function testTargetIdZeroFails(): void {
    $source = $this->createArtifact('REQ-001', 'requirement');

    $link = TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => $source->id(),
      'target_entity_type' => 'artifact',
      'target_entity_id' => 0,
      'link_type' => 'validates',
    ]);
    $violations = $link->validate();
    $this->assertGreaterThan(0, count($violations), 'Target ID of zero should produce a violation.');
  }

  protected function createArtifact(string $key, string $type): Artifact {
    $artifact = Artifact::create([
      'title' => "Artifact $key",
      'artifact_key' => $key,
      'type' => $type,
    ]);
    $artifact->save();
    return $artifact;
  }

}
