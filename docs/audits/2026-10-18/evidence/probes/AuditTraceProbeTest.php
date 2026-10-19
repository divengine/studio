<?php

declare(strict_types=1);

namespace Drupal\Tests\studio_audit\Kernel;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\Tests\divengine_process\Kernel\DevelopmentTest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
class AuditTraceProbeTest extends DevelopmentTest {
  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'comment', 'taxonomy', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_project'];

  private function record(string $name, array $facts): void {
    file_put_contents(getenv('STUDIO_AUDIT_EVIDENCE'), json_encode(['probe' => $name] + $facts, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
  }

  public function testAuditTraceRevisionChangesInPlace(): void {
    $development = $this->createDevelopment();
    $a = Artifact::create(['type' => 'requirement', 'title' => 'A', 'development' => $development->id(), 'moderation_state' => 'published']); $a->save();
    $b = Artifact::create(['type' => 'requirement', 'title' => 'B', 'development' => $development->id(), 'moderation_state' => 'published']); $b->save();
    $link = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $a->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $b->id(), 'link_type' => 'references', 'rationale' => 'Original rationale']);
    $link->save();
    $revision = $link->getRevisionId();
    $baseline = \Drupal::service('divengine_process.baselines')->take($development, 'Trace baseline');
    $link->set('rationale', 'Changed rationale')->save();
    $saved = \Drupal::entityTypeManager()->getStorage('trace_link')->loadRevision($revision);
    $this->record('trace_revision_overwrite', ['same_revision' => $link->getRevisionId() === $revision, 'baseline_rationale_now' => $saved->get('rationale')->value, 'protected' => \Drupal::service('divengine_process.baselines')->protectsRevision('trace_link', $revision)]);
    $this->assertSame('Changed rationale', $saved->get('rationale')->value);
  }

  public function testAuditPrivateProjectTraceAccess(): void {
    $this->installEntitySchema('project');
    $project = Project::create(['name' => 'Private project title', 'project_key' => 'AUDIT', 'uid' => $this->creator->id()]); $project->save();
    $artifact = Artifact::create(['type' => 'risk', 'title' => 'Public reference']); $artifact->save();
    $link = TraceLink::create(['source_entity_type' => 'project', 'source_entity_id' => $project->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $artifact->id(), 'link_type' => 'references']); $link->save();
    $outsider = $this->createUser(['view trace links']);
    $this->setCurrentUser($outsider);
    $ids = \Drupal::entityTypeManager()->getStorage('trace_link')->getQuery()->accessCheck(TRUE)->execute();
    $facts = ['project_visible' => $project->access('view', $outsider), 'trace_visible' => $link->access('view', $outsider), 'trace_in_access_filtered_query' => in_array((string) $link->id(), array_map('strval', $ids), TRUE), 'label' => $link->label()];
    $this->record('private_project_trace', $facts);
    $this->assertFalse($facts['project_visible']);
    $this->assertTrue($facts['trace_visible']);
  }
}
