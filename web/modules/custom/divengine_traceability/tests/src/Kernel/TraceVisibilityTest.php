<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_traceability\Kernel;

use Drupal\Core\Database\Database;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real endpoint policies must apply to entities, queries and references. */
#[RunTestsInSeparateProcesses]
class TraceVisibilityTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'comment', 'taxonomy', 'node', 'menu_ui', 'file', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_project', 'divengine_qa', 'divengine_knowledge', 'divengine_articles'];

  public function testPrivateEndpointsAreAbsentFromAccessQueriesAndReferences(): void {
    foreach (['user', 'artifact', 'content_moderation_state', 'trace_link', 'development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline', 'project', 'project_task', 'node', 'qa_test_plan', 'qa_test_suite', 'qa_test_run', 'qa_test_execution', 'qa_step_result', 'qa_log'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['node', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_knowledge']);
    \Drupal\field\Entity\FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    $this->installConfig('divengine_articles');
    $this->createUser(); // Consume uid 1; both test accounts are ordinary users.
    $permissions = ['view trace links', 'view trace link revisions', 'view artifact', 'use unified process', 'use projects', 'use qa', 'execute qa tests', 'access content'];
    $owner = $this->createUser($permissions);
    $outsider = $this->createUser($permissions);
    $this->setCurrentUser($owner);
    $private = Development::create(['name' => 'Private context']); $private->save();
    $shared = Development::create(['name' => 'Shared context', 'members' => [$outsider->id()]]); $shared->save();
    $project = Project::create(['name' => 'Secret project', 'project_key' => 'SECRET', 'uid' => $owner->id()]); $project->save();
    $task = ProjectTask::create(['name' => 'Secret task', 'project' => $project->id()]); $task->save();
    $article = Node::create(['type' => 'knowledge_article', 'title' => 'Secret article', 'status' => 1, 'kb_development' => $private->id()]); $article->save();
    $plan = QaTestPlan::create(['name' => 'Plan', 'development' => $shared->id()]); $plan->save();
    $run = QaTestRun::create(['name' => 'Secret run', 'plan' => $plan->id(), 'tester' => $owner->id()]); $run->save();
    $public = Artifact::create(['type' => 'risk', 'title' => 'Public risk', 'moderation_state' => 'published']); $public->save();
    $other = Artifact::create(['type' => 'risk', 'title' => 'Another risk', 'moderation_state' => 'published']); $other->save();
    $links = [];
    foreach ([$project, $task, $article, $run] as $end) {
      $link = TraceLink::create(['source_entity_type' => $end->getEntityTypeId(), 'source_entity_id' => $end->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $public->id(), 'link_type' => 'references', 'rationale' => 'Confidential rationale']);
      $link->save();
      $links[] = $link;
      $this->assertTrue($end->access('view', $owner), $end->getEntityTypeId());
      $this->assertTrue($link->access('view', $owner));
      $this->assertNotSame('Restricted trace', $link->label());
    }
    $visible = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $public->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $other->id(), 'link_type' => 'references', 'rationale' => 'Public rationale']); $visible->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('trace_link');
    $this->assertCount(5, $storage->getQuery()->accessCheck(TRUE)->execute());
    // Explicit account metadata must take precedence over the current owner.
    $this->assertSame([(string) $visible->id()], array_map('strval', array_values($storage->getQuery()->accessCheck(TRUE)->addMetaData('account', $outsider)->execute())));
    $this->setCurrentUser($outsider);
    $this->assertTrue($shared->access('view', $outsider), 'QA restriction is narrower than Development visibility.');
    foreach ($links as $link) {
      $this->assertFalse($link->access('view', $outsider));
      $this->assertFalse($link->access('view revision', $outsider));
      $this->assertSame('Restricted trace', $link->label());
      $this->assertSame([], $this->container->get('entity_type.manager')->getListBuilder('trace_link')->buildRow($link));
    }
    $this->assertSame(1, (int) $storage->getQuery()->accessCheck(TRUE)->count()->execute());
    $this->assertSame([(string) $visible->id()], array_map('strval', array_values($storage->getQuery()->accessCheck(TRUE)->sort('id')->range(0, 1)->execute())), 'Hidden rows cannot consume a page.');
    $count = Database::getConnection()->select('trace_link', 't')->addTag('trace_link_access')->countQuery()->execute()->fetchField();
    $this->assertSame(1, (int) $count, 'Tagged SQL/Views counts are filtered too.');
    $panel = $this->container->get('divengine_traceability.trace_panel')->build($public);
    $this->assertSame('1', $panel['#props']['count']);
    $this->assertStringNotContainsString('Confidential rationale', serialize($panel));
    $this->assertContains('user', $panel['#cache']['contexts']);
    $this->assertContains('project_list', $panel['#cache']['tags']);
    $this->assertContains('development:' . $private->id(), $panel['#cache']['tags']);
    $cache = $this->container->get('cache.render');
    $cache->set('trace-collection-test', 'private response', time() + 3600, ['trace_link_list']);
    $private->set('members', [$outsider->id()])->save();
    $this->assertFalse($cache->get('trace-collection-test'), 'Changing membership invalidates cached trace collections.');
    // A trace with a missing endpoint fails closed, even with view permission.
    // Storage refuses to write such a trace (C08), so it stands for damaged
    // data stored earlier: the end is broken with SQL in the disposable test
    // database only.
    $broken = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $other->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $public->id(), 'link_type' => 'references']); $broken->save();
    foreach (['trace_link', 'trace_link_revision'] as $table) {
      Database::getConnection()->update($table)->fields(['source_entity_type' => 'project', 'source_entity_id' => 999999])->condition('id', $broken->id())->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('trace_link')->resetCache();
    $broken = TraceLink::load($broken->id());
    $this->assertFalse($broken->access('view', $outsider));
    $this->assertSame('Restricted trace', $broken->label());
  }

}
