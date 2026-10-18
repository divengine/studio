<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stable keys and labels of test management.
 */
final class QaVocabulary {

  /**
   * Results that count as recorded.
   */
  const TERMINAL = ['passed', 'failed', 'blocked', 'skipped'];

  public static function planStates(): array {
    return [
      'draft' => new TranslatableMarkup('Draft'),
      'active' => new TranslatableMarkup('Active'),
      'closed' => new TranslatableMarkup('Closed'),
    ];
  }

  public static function caseStates(): array {
    return [
      'draft' => new TranslatableMarkup('Draft'),
      'ready' => new TranslatableMarkup('Ready'),
      'obsolete' => new TranslatableMarkup('Obsolete'),
    ];
  }

  public static function runStates(): array {
    return [
      'draft' => new TranslatableMarkup('Draft'),
      'in_progress' => new TranslatableMarkup('In Progress'),
      'completed' => new TranslatableMarkup('Completed'),
      'cancelled' => new TranslatableMarkup('Cancelled'),
    ];
  }

  public static function results(): array {
    return [
      'pending' => new TranslatableMarkup('Pending'),
      'passed' => new TranslatableMarkup('Passed'),
      'failed' => new TranslatableMarkup('Failed'),
      'blocked' => new TranslatableMarkup('Blocked'),
      'skipped' => new TranslatableMarkup('Skipped'),
    ];
  }

  public static function lastResults(): array {
    return [
      'passed' => new TranslatableMarkup('Passed'),
      'failed' => new TranslatableMarkup('Failed'),
      'blocked' => new TranslatableMarkup('Blocked'),
      'skipped' => new TranslatableMarkup('Skipped'),
      'no_exec' => new TranslatableMarkup('Not Executed'),
    ];
  }

  public static function origins(): array {
    return [
      'planned' => new TranslatableMarkup('Planned'),
      'ad_hoc' => new TranslatableMarkup('Ad hoc'),
    ];
  }

  public static function levels(): array {
    return [
      'step' => new TranslatableMarkup('Step'),
      'execution' => new TranslatableMarkup('Execution'),
    ];
  }

  public static function issueStates(): array {
    return [
      'open' => new TranslatableMarkup('Open'),
      'in_progress' => new TranslatableMarkup('In Progress'),
      'resolved' => new TranslatableMarkup('Resolved'),
      'cancelled' => new TranslatableMarkup('Cancelled'),
    ];
  }

  public static function issuePriorities(): array {
    return [
      '0' => new TranslatableMarkup('Low'),
      '1' => new TranslatableMarkup('Normal'),
      '2' => new TranslatableMarkup('High'),
      '3' => new TranslatableMarkup('Urgent'),
    ];
  }

  /**
   * Badge tone of a result or state.
   */
  public static function tone(string $key): string {
    return match ($key) {
      'passed', 'completed', 'ready', 'active', 'resolved' => 'success',
      'failed', 'cancelled' => 'danger',
      'blocked', 'in_progress' => 'warning',
      'skipped', 'obsolete', 'closed' => 'neutral',
      default => 'info',
    };
  }

  /**
   * Allowed values function for the list fields of QA entities.
   */
  public static function allowedValues(FieldStorageDefinitionInterface $definition, ?FieldableEntityInterface $entity = NULL, ?bool &$cacheable = TRUE): array {
    return match ($definition->getTargetEntityTypeId() . '.' . $definition->getName()) {
      'qa_test_plan.state', 'qa_test_suite.state' => static::planStates(),
      'qa_test_case.state' => static::caseStates(),
      'qa_test_case.last_result' => static::lastResults(),
      'qa_test_run.state' => static::runStates(),
      'qa_test_execution.state', 'qa_step_result.state' => static::results(),
      'qa_result_correction.old_state', 'qa_result_correction.new_state' => static::results(),
      'qa_test_execution.origin' => static::origins(),
      'qa_result_correction.level' => static::levels(),
      'qa_issue.state' => static::issueStates(),
      'qa_issue.priority' => static::issuePriorities(),
      default => [],
    };
  }

}
