<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\ReadinessCriterion;

use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_qa\QaPermissions;
use Drupal\divengine_qa\QaVerification;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base of the verification criteria: each gap names the record and what is
 * missing; there is no coverage score.
 */
abstract class QaVerificationCriterionBase extends ReadinessCriterionBase {

  protected QaVerification $verification;

  protected AccountInterface $currentUser;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->verification = $container->get('divengine_qa.verification');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * Met when every record has passing tests, read as the current user.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $records
   */
  protected function verified(array $records): ReadinessResult {
    if (!QaPermissions::isUser($this->currentUser)) {
      return ReadinessResult::fromReasons([(string) $this->t('Not evaluated: you have no access to Test Plans.')]);
    }
    $gaps = [];
    foreach ($records as $record) {
      $gaps = array_merge($gaps, $this->verification->gaps($record));
    }
    return ReadinessResult::fromReasons($gaps);
  }

}
