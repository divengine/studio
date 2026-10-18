<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\QaException;
use Drupal\divengine_qa\QaPermissions;
use Drupal\divengine_qa\QaRunWorkflow;
use Drupal\divengine_qa\TestUrl;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the QaRunRules constraint.
 */
class QaRunRulesConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected QaRunWorkflow $runs,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('divengine_qa.runs'),
    );
  }

  public function validate(mixed $run, Constraint $constraint): void {
    if (!$run instanceof QaTestRun || $run->isTrusted()) {
      return;
    }
    /** @var \Drupal\divengine_qa\Plugin\Validation\Constraint\QaRunRulesConstraint $constraint */
    $url = TestUrl::normalize($run->get('test_url')->value);
    if (!TestUrl::isValid($url)) {
      $this->context->buildViolation($constraint->url)->setParameter('@value', $url)->atPath('test_url')->addViolation();
    }
    $original = $run->isNew() ? NULL : $this->entityTypeManager->getStorage('qa_test_run')->loadUnchanged($run->id());
    $flags_changed = $original
      ? ((bool) $original->get('is_self_service')->value !== (bool) $run->get('is_self_service')->value || (bool) $original->get('is_auto_completed')->value !== (bool) $run->get('is_auto_completed')->value)
      : ($run->get('is_self_service')->value || $run->get('is_auto_completed')->value);
    if ($flags_changed) {
      $this->context->buildViolation($constraint->flags)->addViolation();
    }
    if (!$original) {
      return;
    }
    if ($original->isHistorical()) {
      $this->context->buildViolation($constraint->historical)->addViolation();
      return;
    }
    if ((string) $original->get('plan')->target_id !== (string) $run->get('plan')->target_id && $this->runs->executions($original)) {
      $this->context->buildViolation($constraint->plan)->atPath('plan')->addViolation();
    }
    if ((string) $original->get('tester')->target_id !== (string) $run->get('tester')->target_id) {
      if (!QaPermissions::isLead($this->currentUser)) {
        $this->context->buildViolation($constraint->assign)->atPath('tester')->addViolation();
        return;
      }
      try {
        $this->runs->checkTesterChange($original);
      }
      catch (QaException $e) {
        $this->context->buildViolation($e->getMessage())->atPath('tester')->addViolation();
        return;
      }
      if (!$original->get('tester')->isEmpty()) {
        $this->context->buildViolation($constraint->reassign)->atPath('tester')->addViolation();
      }
    }
  }

}
