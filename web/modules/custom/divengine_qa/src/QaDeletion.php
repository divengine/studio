<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Which QA records may be deleted.
 *
 * Results, corrections and finished runs are historical evidence. A record
 * removed as part of the deletion of its parent (a draft run's snapshot, a
 * case's steps) is only checked for the rules that hold even then.
 */
final class QaDeletion {

  /**
   * The reason a record cannot be deleted, or NULL when it can.
   */
  public static function refusal(EntityInterface $entity): ?TranslatableMarkup {
    $storage = fn (string $type) => \Drupal::entityTypeManager()->getStorage($type);
    $count = fn (string $type, string $field, $id) => (int) $storage($type)->getQuery()->accessCheck(FALSE)->condition($field, $id)->count()->execute();
    switch ($entity->getEntityTypeId()) {
      case 'qa_test_run':
        if ($count('qa_result_correction', 'run', $entity->id())) {
          return new TranslatableMarkup('Test Runs with a result correction history cannot be deleted.');
        }
        if (in_array($entity->get('state')->value, ['completed', 'cancelled'], TRUE)) {
          return new TranslatableMarkup('Completed or Cancelled Test Runs are historical evidence and cannot be deleted.');
        }
        if (\Drupal::service('divengine_qa.runs')->hasRecordedResults($entity)) {
          return new TranslatableMarkup('Test Runs with recorded results are historical evidence and cannot be deleted. Cancel the Run instead.');
        }
        return NULL;

      case 'qa_test_execution':
        if ($count('qa_result_correction', 'execution', $entity->id())) {
          return new TranslatableMarkup('Executions with a result correction history cannot be deleted.');
        }
        // The run is gone when its deletion removes its snapshot.
        if (!$run = $entity->get('run')->entity) {
          return NULL;
        }
        if (in_array($run->get('state')->value, ['completed', 'cancelled'], TRUE)) {
          return new TranslatableMarkup('Executions of Completed or Cancelled Runs are historical evidence and cannot be deleted.');
        }
        if ($run->get('state')->value !== 'draft') {
          return new TranslatableMarkup('Executions of a started Test Run are historical evidence and cannot be deleted. Cancel the Run instead.');
        }
        return NULL;

      case 'qa_step_result':
        if ($count('qa_result_correction', 'step_result', $entity->id())) {
          return new TranslatableMarkup('Step Results with a result correction history cannot be deleted.');
        }
        $run = $entity->get('run')->entity;
        if ($run && in_array($run->get('state')->value, ['completed', 'cancelled'], TRUE)) {
          return new TranslatableMarkup('Step Results of Completed or Cancelled Runs are historical evidence and cannot be deleted.');
        }
        if ($entity->get('execution')->entity) {
          return new TranslatableMarkup('Step Results are part of the historical Run snapshot and cannot be deleted.');
        }
        return NULL;

      case 'qa_test_case':
        if ($count('qa_test_execution', 'test_case', $entity->id())) {
          return new TranslatableMarkup('Test Cases that were executed in a Test Run cannot be deleted. Mark them Obsolete instead.');
        }
        return NULL;

      case 'qa_test_suite':
        if ($count('qa_test_suite', 'parent', $entity->id())) {
          return new TranslatableMarkup('Delete or move the child suites of this Test Suite first.');
        }
        return NULL;

      case 'qa_result_correction':
        return new TranslatableMarkup('The result correction history is an immutable audit trail and cannot be deleted.');
    }
    return NULL;
  }

  /**
   * Refuses the deletion of records holding history.
   *
   * @throws \Drupal\divengine_qa\QaException
   */
  public static function assertDeletable(array $entities): void {
    foreach ($entities as $entity) {
      if ($reason = static::refusal($entity)) {
        throw new QaException((string) $reason);
      }
    }
  }

}
