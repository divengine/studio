<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C08: who may link which ends, and what any writer may store.
 */
#[RunTestsInSeparateProcesses]
class TraceIntegrityTest extends KernelTestBase {

  use ConcurrentWriterTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'comment', 'taxonomy', 'node', 'menu_ui', 'file', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_project', 'divengine_knowledge', 'divengine_articles'];

  protected UserInterface $owner;

  protected UserInterface $outsider;

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'artifact', 'content_moderation_state', 'trace_link', 'development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline', 'project', 'project_task', 'node'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['node', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_knowledge']);
    \Drupal\field\Entity\FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    $this->installConfig('divengine_articles');
    $this->createUser();
    $permissions = ['view trace links', 'create trace links', 'edit trace links', 'view artifact', 'use unified process', 'use projects', 'access content'];
    $this->owner = $this->createUser($permissions);
    $this->outsider = $this->createUser($permissions);
    $this->setCurrentUser($this->owner);
  }

  protected function link(object $source, object $target, string $type = 'references'): TraceLink {
    return TraceLink::create([
      'source_entity_type' => $source->getEntityTypeId(),
      'source_entity_id' => $source->id(),
      'target_entity_type' => $target->getEntityTypeId(),
      'target_entity_id' => $target->id(),
      'link_type' => $type,
    ]);
  }

  protected function risk(string $title, ?Development $development = NULL): Artifact {
    $risk = Artifact::create(['type' => 'risk', 'title' => $title, 'moderation_state' => 'published', 'development' => $development?->id()]);
    $risk->save();
    return $risk;
  }

  protected function useCase(string $title, Development $development): Artifact {
    $use_case = Artifact::create(['type' => 'use_case', 'title' => $title, 'development' => $development->id()]);
    $use_case->save();
    return $use_case;
  }

  protected function messages(TraceLink $link): array {
    return array_map(fn ($v) => strip_tags((string) $v->getMessage()), iterator_to_array($link->validate()));
  }

  public function testWriterMustSeeBothEnds(): void {
    $private = Development::create(['name' => 'Private']);
    $private->save();
    $secret = $this->risk('Secret risk', $private);
    $public = $this->risk('Public risk');

    $this->setCurrentUser($this->outsider);
    $this->assertContains('You cannot trace to or from a record you are not allowed to see.', $this->messages($this->link($public, $secret)));
    $this->assertContains('You cannot trace to or from a record you are not allowed to see.', $this->messages($this->link($secret, $public)));

    $this->setCurrentUser($this->owner);
    $link = $this->link($public, $secret);
    $this->assertSame([], $this->messages($link));
    $link->save();

    // Changing an end is checked again; editing the rationale is not.
    $this->setCurrentUser($this->outsider);
    $other = $this->risk('Other public risk');
    $editable = $this->link($public, $other);
    $editable->save();
    $editable->set('target_entity_id', $secret->id());
    $this->assertContains('You cannot trace to or from a record you are not allowed to see.', $this->messages($editable));
  }

  public function testScopesAreResolvedForEveryRegisteredType(): void {
    $first = Development::create(['name' => 'First']);
    $first->save();
    $second = Development::create(['name' => 'Second']);
    $second->save();
    $risk = $this->risk('Risk of the first', $first);
    $same = Node::create(['type' => 'knowledge_article', 'title' => 'Article of the first', 'kb_development' => $first->id()]);
    $same->save();
    $foreign = Node::create(['type' => 'knowledge_article', 'title' => 'Article of the second', 'kb_development' => $second->id()]);
    $foreign->save();
    $project = Project::create(['name' => 'Delivery', 'project_key' => 'DEL', 'uid' => $this->owner->id()]);
    $project->save();

    $this->assertContains('Traces between records of a Development stay inside that Development.', $this->messages($this->link($risk, $foreign)), 'kb_development counts as the Development of an article.');
    try {
      $this->link($risk, $foreign)->save();
      $this->fail('A cross-Development trace must not be stored by a writer that skips validation.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('cross_development', $e->getMessage());
    }
    $this->link($risk, $same)->save();
    // Projects belong to no Development and are not forced into one.
    $this->link($risk, $project)->save();
    $this->link($project, $foreign)->save();
    $this->assertSame(3, (int) \Drupal::entityQuery('trace_link')->accessCheck(FALSE)->count()->execute());
  }

  public function testIntegrityHoldsForDirectWriters(): void {
    $a = $this->risk('A');
    $b = $this->risk('B');
    $this->link($a, $b)->save();
    try {
      $this->link($a, $b)->save();
      $this->fail('A duplicate must not be stored.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('already exists', $e->getMessage());
    }
    foreach ([$this->link($a, $a), TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $a->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => 999999, 'link_type' => 'references'])] as $invalid) {
      try {
        $invalid->save();
        $this->fail('An invalid link must not be stored.');
      }
      catch (EntityStorageException) {
      }
    }
    $this->assertSame(1, (int) \Drupal::entityQuery('trace_link')->accessCheck(FALSE)->count()->execute());
  }

  public function testCyclesOnlyForAcyclicRelations(): void {
    $development = Development::create(['name' => 'Use cases']);
    $development->save();
    $login = $this->useCase('Log in', $development);
    $checkout = $this->useCase('Check out', $development);
    $pay = $this->useCase('Pay', $development);
    $this->link($checkout, $pay, 'includes')->save();
    $this->link($pay, $login, 'includes')->save();
    try {
      $this->link($login, $checkout, 'includes')->save();
      $this->fail('Includes must not close a cycle.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('cycle', $e->getMessage());
    }
    // Extends keeps its accepted semantics: it may point back.
    $this->link($login, $checkout, 'extends')->save();
    $this->link($checkout, $login, 'extends')->save();
    $this->assertSame(4, (int) \Drupal::entityQuery('trace_link')->accessCheck(FALSE)->count()->execute());
  }

  public function testConcurrentDuplicateIsRefused(): void {
    $a = $this->risk('A');
    $b = $this->risk('B');
    // Another request stores the same link while this one waits for it.
    \Drupal::lock()->holdUntilWaitedFor('divengine_trace_link:', fn () => $this->saveAsAnotherRequest($this->link($a, $b)));
    try {
      $this->link($a, $b)->save();
      $this->fail('The second writer must see the link stored meanwhile.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('already exists', $e->getMessage());
    }
    $this->assertSame(1, (int) \Drupal::entityQuery('trace_link')->accessCheck(FALSE)->count()->execute());
    $this->assertLockReleased('divengine_trace_link:' . hash('sha256', implode('|', ['references', 'artifact', $a->id(), 'artifact', $b->id()])));
  }

  public function testConcurrentCycleIsRefused(): void {
    $development = Development::create(['name' => 'Concurrent use cases']);
    $development->save();
    $one = $this->useCase('One', $development);
    $two = $this->useCase('Two', $development);
    // Another request stores the opposite inclusion while this one waits.
    \Drupal::lock()->holdUntilWaitedFor('divengine_process_trace_graph:includes', fn () => $this->saveAsAnotherRequest($this->link($one, $two, 'includes')));
    try {
      $this->link($two, $one, 'includes')->save();
      $this->fail('The second writer must see the inclusion stored meanwhile.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('cycle', $e->getMessage());
    }
    $this->assertSame(1, (int) \Drupal::entityQuery('trace_link')->accessCheck(FALSE)->count()->execute());
    $this->assertLockReleased('divengine_process_trace_graph:includes');
  }

  public function testUnrelatedWritersAreNotSerialized(): void {
    $a = $this->risk('A');
    $b = $this->risk('B');
    $c = $this->risk('C');
    \Drupal::lock()->holdUntilWaitedFor('divengine_trace_link:' . hash('sha256', implode('|', ['references', 'artifact', $a->id(), 'artifact', $b->id()])), fn () => $this->fail('Another triple must not wait.'));
    $this->link($a, $c)->save();
    $this->assertLockReleased('divengine_trace_link:' . hash('sha256', implode('|', ['references', 'artifact', $a->id(), 'artifact', $c->id()])));
    $this->assertSame(1, (int) \Drupal::entityQuery('trace_link')->accessCheck(FALSE)->count()->execute());
  }

}
