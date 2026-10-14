<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_traceability\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\divengine_traceability\Entity\TraceLinkType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers configurable link types, their rules, uniqueness and cleanup.
 */
#[RunTestsInSeparateProcesses]
class TraceLinkTypeRulesTest extends KernelTestBase {

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
    'divengine_traceability',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('trace_link');
    $this->installConfig(['divengine_artifact', 'divengine_traceability']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  public function testDefaultTypesAreOrderedAllowedValues(): void {
    $values = TraceLinkType::allowedValues();
    $this->assertCount(18, $values);
    $this->assertSame('refines', array_key_first($values));
    $this->assertSame('Realizes', $values['realizes']);
  }

  public function testUnknownLinkTypeFails(): void {
    $link = $this->link($this->artifact('REQ-1', 'requirement'), 'no_such_type', $this->artifact('TC-1', 'test_case'));
    // The field's allowed values reject it first; the entity rule is the backstop.
    $this->assertNotEmpty($this->messages($link));
    $errors = \Drupal::service('divengine_traceability.trace_link_validator')
      ->validateType('no_such_type', 'artifact', (int) $link->getSourceEntityId(), 'artifact', (int) $link->getTargetEntityId());
    $this->assertSame(['Link type "no_such_type" does not exist.'], $errors);
  }

  public function testDuplicateLinkFailsButResavingSucceeds(): void {
    $requirement = $this->artifact('REQ-1', 'requirement');
    $test = $this->artifact('TC-1', 'test_case');
    $first = $this->link($test, 'verifies', $requirement);
    $this->assertSame([], $this->messages($first));
    $first->save();

    $this->assertSame([], $this->messages($first), 'A saved link does not collide with itself.');
    $this->assertContains('This trace link already exists.', $this->messages($this->link($test, 'verifies', $requirement)));
    $this->assertSame([], $this->messages($this->link($test, 'tests', $requirement)), 'Another type between the same ends is allowed.');
  }

  public function testSourceAndTargetRules(): void {
    $type = TraceLinkType::load('verifies');
    $type->set('source_rules', ['artifact:test_case'])->set('target_rules', ['artifact:requirement', 'artifact:use_case'])->save();

    $requirement = $this->artifact('REQ-1', 'requirement');
    $test = $this->artifact('TC-1', 'test_case');
    $this->assertSame([], $this->messages($this->link($test, 'verifies', $requirement)));

    $messages = $this->messages($this->link($requirement, 'verifies', $test));
    $this->assertContains('"Verifies" links cannot start from artifact:requirement.', $messages);
    $this->assertContains('"Verifies" links cannot point to artifact:test_case.', $messages);
  }

  public function testSupersedesRequiresSameKind(): void {
    $old = $this->artifact('REQ-1', 'requirement');
    $new = $this->artifact('REQ-2', 'requirement');
    $test = $this->artifact('TC-1', 'test_case');
    $this->assertSame([], $this->messages($this->link($new, 'supersedes', $old)));
    $this->assertContains('"Supersedes" links must join two entities of the same kind.', $this->messages($this->link($test, 'supersedes', $old)));
  }

  public function testDeletingAnEndDeletesItsLinks(): void {
    $requirement = $this->artifact('REQ-1', 'requirement');
    $test = $this->artifact('TC-1', 'test_case');
    $use_case = $this->artifact('UC-1', 'use_case');
    $this->link($test, 'verifies', $requirement)->save();
    $this->link($use_case, 'refines', $requirement)->save();
    $kept = $this->link($use_case, 'references', $test);
    $kept->save();

    $requirement->delete();

    $remaining = \Drupal::entityTypeManager()->getStorage('trace_link')->loadMultiple();
    $this->assertSame([(int) $kept->id()], array_map('intval', array_keys($remaining)));
  }

  public function testLabelAndReviewTimestamp(): void {
    $reviewer = $this->createUser();
    $link = $this->link($this->artifact('TC-1', 'test_case'), 'verifies', $this->artifact('REQ-1', 'requirement'));
    $link->save();
    $this->assertSame('TC-1 verifies REQ-1', $link->label());
    $this->assertTrue($link->get('reviewed_at')->isEmpty());

    $link->set('reviewed_by', $reviewer->id())->save();
    $this->assertFalse($link->get('reviewed_at')->isEmpty());

    $link->set('reviewed_by', NULL)->save();
    $this->assertTrue($link->get('reviewed_at')->isEmpty());
  }

  protected function artifact(string $key, string $type): Artifact {
    $artifact = Artifact::create(['title' => "Artifact $key", 'artifact_key' => $key, 'type' => $type]);
    $artifact->save();
    return $artifact;
  }

  protected function link(Artifact $source, string $type, Artifact $target): TraceLink {
    return TraceLink::create([
      'source_entity_type' => 'artifact',
      'source_entity_id' => $source->id(),
      'target_entity_type' => 'artifact',
      'target_entity_id' => $target->id(),
      'link_type' => $type,
    ]);
  }

  /**
   * @return string[]
   */
  protected function messages(TraceLink $link): array {
    $messages = [];
    foreach ($link->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    return $messages;
  }

}
