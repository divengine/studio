<?php

declare(strict_types=1);

namespace Drupal\Tests\studio_review\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_artifact\ArtifactAccessControlHandler;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\divengine_traceability\TraceVisibility;
use Drupal\Tests\divengine_process\Kernel\DevelopmentTest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Instrumentation only; delegates unchanged to the shipped access handler. */
class CountingArtifactAccess extends ArtifactAccessControlHandler {
  public static int $calls = 0;

  public function access(EntityInterface $entity, $operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    self::$calls++;
    return parent::access($entity, $operation, $account, $return_as_object);
  }
}

/** Instrumentation only; records aliases before delegating unchanged. */
class CountingTraceVisibility extends TraceVisibility {
  public array $aliases = [];
  public function alterQuery(\Drupal\Core\Database\Query\SelectInterface $query): void {
    $tables = $this->entityTypeManager->getDefinition('trace_link');
    $names = array_filter([$tables->getBaseTable(), $tables->getDataTable(), $tables->getRevisionTable(), $tables->getRevisionDataTable()]);
    foreach ($query->getTables() as $alias => $info) {
      if (is_string($info['table']) && in_array($info['table'], $names, TRUE)) {
        $this->aliases[] = ['alias' => $alias, 'table' => $info['table']];
      }
    }
    parent::alterQuery($query);
  }
}

/** Bounded review diagnostic, run only in a fresh disposable PostgreSQL DB. */
#[RunTestsInSeparateProcesses]
class TraceVisibilityReviewProbeTest extends DevelopmentTest {

  public function testReviewTraceVisibilityScaling(): void {
    $this->setCurrentUser($this->createUser(['use unified process', 'view artifact', 'view trace links']));
    $development = $this->createDevelopment('Performance fixture');
    $manager = \Drupal::entityTypeManager();
    $manager->getDefinition('artifact')->setHandlerClass('access', CountingArtifactAccess::class);
    $this->assertInstanceOf(CountingArtifactAccess::class, $manager->getAccessControlHandler('artifact'));
    $db = \Drupal::database();
    $a = Artifact::create(['type' => 'risk', 'title' => 'Fixture', 'development' => $development->id(), 'moderation_state' => 'published']); $a->save();
    $b = Artifact::create(['type' => 'risk', 'title' => 'Other fixture', 'development' => $development->id(), 'moderation_state' => 'published']); $b->save();
    $trace = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $a->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $b->id(), 'link_type' => 'references']); $trace->save();
    $templates = [];
    foreach (['artifact', 'artifact_revision', 'trace_link', 'trace_link_revision'] as $table) {
      $templates[$table] = $db->select($table, 't')->fields('t')->condition('id', 1)->execute()->fetchAssoc();
    }
    $previous = 2;
    $previous_traces = 1;
    foreach ([100, 1000, 3000] as $size) {
      // Bulk SQL seeds a synthetic graph only inside this disposable fixture.
      // Fixture creation is excluded from timings; no production import API is
      // represented by this shortcut, and no baseline is created here.
      foreach ($templates as $table => $template) {
        $start = str_starts_with($table, 'artifact') ? $previous + 1 : $previous_traces + 1;
        foreach (array_chunk(range($start, $size), 100) as $batch) {
          $insert = $db->insert($table)->fields(array_keys($template));
          foreach ($batch as $id) {
            $row = $template;
            $row['id'] = $id;
            $row['revision_id'] = $id;
            if (array_key_exists('uuid', $row)) {
              $row['uuid'] = sprintf('00000000-0000-4000-8000-%012d', (str_starts_with($table, 'artifact') ? 0 : 100000) + $id);
            }
            if (array_key_exists('artifact_key', $row)) {
              $row['artifact_key'] = 'PERF-' . $id;
            }
            if (array_key_exists('source_entity_id', $row)) {
              $row['source_entity_id'] = $id;
              $row['target_entity_id'] = 1;
            }
            $insert->values(array_values($row));
          }
          $insert->execute();
        }
      }
      $previous = $previous_traces = $size;
      foreach (['page_cold', 'count_cold', 'page_warm'] as $mode) {
        if ($mode !== 'page_warm') {
          $manager->getStorage('artifact')->resetCache();
          $manager->getAccessControlHandler('artifact')->resetCache();
          $manager->getAccessControlHandler('development')->resetCache();
          $this->container->set('divengine_traceability.visibility', new CountingTraceVisibility($db, $manager));
        }
        CountingArtifactAccess::$calls = 0;
        $this->container->get('divengine_traceability.visibility')->aliases = [];
        $key = 'review-' . $size . '-' . $mode;
        Database::startLog($key);
        $start_time = hrtime(TRUE);
        $query = $manager->getStorage('trace_link')->getQuery()->accessCheck(TRUE)->condition('source_entity_id', 1);
        $result = $mode === 'count_cold' ? $query->count()->execute() : $query->range(0, 10)->execute();
        $elapsed = (hrtime(TRUE) - $start_time) / 1e6;
        $log = Database::getLog($key);
        $db->getLogger()->end($key);
        $final_query = end($log);
        $row = [
          'entities' => $size, 'traces' => $size, 'mode' => $mode,
          'returned_or_count' => is_array($result) ? count($result) : (int) $result,
          'elapsed_ms' => round($elapsed, 3),
          'sql_queries' => count($log),
          'access_calls' => CountingArtifactAccess::$calls,
          'filtered_aliases' => $this->container->get('divengine_traceability.visibility')->aliases,
          'final_sql_bytes' => strlen($final_query['query']),
          'final_sql_parameters' => count($final_query['args']),
          'cache_tags' => count($this->container->get('divengine_traceability.visibility')->queryCacheability(\Drupal::currentUser())->getCacheTags()),
        ];
        file_put_contents(getenv('STUDIO_REVIEW_EVIDENCE'), json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        $this->assertSame(1, $row['returned_or_count']);
        $this->assertSame(count($row['filtered_aliases']) * ($size + 2), $row['access_calls']);
        if ($elapsed > 30000) {
          return; // Keep the review bounded; do not stress the shared VM.
        }
      }
    }
  }

  public function testReviewRetentionUninstallValidation(): void {
    $development = $this->createDevelopment('Retention fixture');
    \Drupal::service('divengine_process.baselines')->take($development, 'Empty retained baseline');
    $validator = new \Drupal\Core\Entity\ContentUninstallValidator(\Drupal::entityTypeManager(), \Drupal::translation());
    $reasons = array_map('strval', $validator->validate('divengine_process'));
    $this->assertNotEmpty($reasons);
    $this->assertNotEmpty(array_filter($reasons, fn ($reason) => str_contains($reason, 'Baseline')));
    file_put_contents(getenv('STUDIO_REVIEW_EVIDENCE'), json_encode(['probe' => 'retention_uninstall', 'blocked' => TRUE, 'baseline_reason_present' => TRUE, 'empty_baseline' => TRUE, 'uninstall_executed' => FALSE], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
  }

}
