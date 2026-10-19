<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_traceability\Entity\TraceLink;

/** Shared real-entity fixtures for historical persistence regressions. */
trait BaselineFixtureTrait {

  protected function installBaselineFixture(): void {
    foreach (['user', 'artifact', 'content_moderation_state', 'trace_link', 'development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['divengine_artifact', 'divengine_traceability', 'divengine_process']);
    $this->createUser();
    $this->setCurrentUser($this->createUser(['use unified process', 'view artifact', 'view trace links', 'view trace link revisions']));
  }

  protected function baselineFixture(): array {
    $development = Development::create(['name' => 'History regression']); $development->save();
    $artifacts = [];
    foreach (['First', 'Second', 'Third'] as $title) {
      $artifact = Artifact::create(['type' => 'requirement', 'title' => $title, 'development' => $development->id(), 'moderation_state' => 'published']); $artifact->save();
      $artifacts[] = $artifact;
    }
    $trace = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $artifacts[0]->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $artifacts[1]->id(), 'link_type' => 'references', 'rationale' => 'Original rationale']); $trace->save();
    $baseline = \Drupal::service('divengine_process.baselines')->take($development, 'Captured history');
    return [$baseline, $trace, $artifacts];
  }

}
