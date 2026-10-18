<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\divengine_qa\QaVerification;
use Drupal\divengine_traceability\Entity\TraceLinkInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the QaVerificationTrace constraint.
 */
class QaVerificationTraceConstraintValidator extends ConstraintValidator {

  public function validate(mixed $link, Constraint $constraint): void {
    if (!$link instanceof TraceLinkInterface) {
      return;
    }
    $from_test = in_array($link->getSourceEntityType(), QaVerification::QA_TARGETS, TRUE);
    $to_test = in_array($link->getTargetEntityType(), QaVerification::QA_TARGETS, TRUE);
    if (!$from_test && !$to_test) {
      return;
    }
    if ($to_test || (string) $link->get('link_type')->value !== 'verifies') {
      $this->context->buildViolation($constraint->relation)->atPath('link_type')->addViolation();
      return;
    }
    $target = $link->getTargetEntity();
    if ($target && !QaVerification::isVerifiable($target)) {
      $this->context->buildViolation($constraint->target)->addViolation();
    }
  }

}
