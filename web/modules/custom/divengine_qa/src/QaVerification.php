<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Verification of Unified Process records by tests.
 *
 * A test plan, suite, case or run "verifies" a requirement, use case,
 * change request or work product with an explicit trace. The verification
 * status is derived from the QA results of those tests, read as the user:
 * it is never stored, never set by hand and never inferred from the mere
 * existence of a plan.
 */
class QaVerification {

  use StringTranslationTrait;

  /**
   * QA records that can verify.
   */
  const QA_TARGETS = ['qa_test_plan', 'qa_test_suite', 'qa_test_case', 'qa_test_run'];

  /**
   * Artifact types that can be verified (plus change requests).
   */
  const VERIFIED_BUNDLES = ['requirement', 'use_case', 'work_product'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  public static function statuses(): array {
    return [
      'none' => new TranslatableMarkup('No Verification'),
      'not_run' => new TranslatableMarkup('Not Executed'),
      'failed' => new TranslatableMarkup('Failing'),
      'partial' => new TranslatableMarkup('Partially Passed'),
      'passed' => new TranslatableMarkup('Passed'),
    ];
  }

  /**
   * Whether a record can be verified by tests.
   */
  public static function isVerifiable(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() === 'change_request'
      || ($entity->getEntityTypeId() === 'artifact' && in_array($entity->bundle(), static::VERIFIED_BUNDLES, TRUE));
  }

  /**
   * The tests that verify a record and that the user may see.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function verifiers(EntityInterface $record): array {
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('link_type', 'verifies')
      ->condition('target_entity_type', $record->getEntityTypeId())
      ->condition('target_entity_id', $record->id())
      ->condition('source_entity_type', static::QA_TARGETS, 'IN')
      ->sort('id')
      ->execute();
    $tests = [];
    foreach ($storage->loadMultiple($ids) as $link) {
      $test = $link->getSourceEntity();
      if ($test && $test->access('view', $this->currentUser)) {
        $tests[$test->getEntityTypeId() . ':' . $test->id()] = $test;
      }
    }
    return array_values($tests);
  }

  /**
   * [record, result] pairs of one test, from the QA semantics.
   *
   * - case: its last result, unless Obsolete;
   * - suite (with its child suites) and plan: that of each non-obsolete
   *   case;
   * - run: the result of each execution when Completed (pending counts as
   *   not executed); a run not completed is not executed.
   *
   * @return array<int, array{0: \Drupal\Core\Entity\EntityInterface, 1: string}>
   */
  public function results(ContentEntityInterface $test): array {
    $cases = $this->entityTypeManager->getStorage('qa_test_case');
    if ($test->getEntityTypeId() === 'qa_test_run') {
      if ($test->get('state')->value !== 'completed') {
        return [[$test, 'no_exec']];
      }
      $results = [];
      foreach (\Drupal::service('divengine_qa.runs')->executions($test) as $execution) {
        $results[] = [$execution->get('test_case')->entity ?? $execution, $execution->getResult() === 'pending' ? 'no_exec' : $execution->getResult()];
      }
      return $results ?: [[$test, 'no_exec']];
    }
    $query = $cases->getQuery()->accessCheck(TRUE)->condition('state', 'obsolete', '<>');
    match ($test->getEntityTypeId()) {
      'qa_test_case' => $query->condition('id', $test->id()),
      'qa_test_suite' => $query->condition('suite', $this->suiteTree($test), 'IN'),
      default => $query->condition('plan', $test->id()),
    };
    $results = [];
    foreach ($cases->loadMultiple($query->execute()) as $case) {
      $results[] = [$case, (string) ($case->get('last_result')->value ?: 'no_exec')];
    }
    return $results ?: [[$test, 'no_exec']];
  }

  /**
   * One status from results: failing if any failed or blocked, passed if
   * all passed, partially passed if some passed, else not executed.
   */
  public static function status(array $results): string {
    if (!$results) {
      return 'none';
    }
    $states = array_unique(array_map(fn ($pair) => $pair[1], $results));
    if (array_intersect($states, ['failed', 'blocked'])) {
      return 'failed';
    }
    if ($states === ['passed']) {
      return 'passed';
    }
    return in_array('passed', $states, TRUE) ? 'partial' : 'not_run';
  }

  /**
   * The status of a record and the tests behind it.
   *
   * @return array{status: string, tests: array, results: array}
   */
  public function verification(EntityInterface $record): array {
    $tests = $this->verifiers($record);
    $results = [];
    foreach ($tests as $test) {
      $results = array_merge($results, $this->results($test));
    }
    return ['status' => static::status($results), 'tests' => $tests, 'results' => $results];
  }

  /**
   * Readable reasons why a record is not verified (none when it is).
   *
   * @return string[]
   */
  public function gaps(EntityInterface $record): array {
    $verification = $this->verification($record);
    $name = $record->getEntityTypeId() === 'artifact' ? trim($record->get('artifact_key')->value . ' ' . $record->label()) : $record->label();
    if ($verification['status'] === 'passed') {
      return [];
    }
    if ($verification['status'] === 'none') {
      return [(string) $this->t('@record has no verification artifact.', ['@record' => $name])];
    }
    $labels = QaVocabulary::lastResults();
    $gaps = [];
    foreach ($verification['results'] as [$test, $result]) {
      if ($result !== 'passed') {
        $gaps[] = (string) $this->t('@record: @test is @result.', ['@record' => $name, '@test' => $test->label(), '@result' => $labels[$result] ?? $result]);
      }
    }
    return $gaps;
  }

  /**
   * IDs of a suite and of all its descendants.
   */
  protected function suiteTree(EntityInterface $suite): array {
    $storage = $this->entityTypeManager->getStorage('qa_test_suite');
    $ids = [(string) $suite->id()];
    $frontier = $ids;
    while ($frontier) {
      $children = array_map('strval', $storage->getQuery()->accessCheck(FALSE)->condition('parent', $frontier, 'IN')->execute());
      $frontier = array_values(array_diff($children, $ids));
      $ids = array_merge($ids, $frontier);
    }
    return $ids;
  }

}
