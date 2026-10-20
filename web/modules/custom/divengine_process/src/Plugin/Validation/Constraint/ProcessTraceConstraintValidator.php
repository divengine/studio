<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\divengine_process\TraceRules;
use Drupal\divengine_traceability\Entity\TraceLinkInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessTrace constraint.
 */
class ProcessTraceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected TraceRules $rules) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_process.trace_rules'));
  }

  public function validate(mixed $link, Constraint $constraint): void {
    if (!$link instanceof TraceLinkInterface) {
      return;
    }
    /** @var \Drupal\divengine_process\Plugin\Validation\Constraint\ProcessTraceConstraint $constraint */
    foreach ($this->rules->broken($link) as $rule) {
      match ($rule) {
        'cross_development' => $this->context->buildViolation($constraint->crossDevelopment)->addViolation(),
        'impacts' => $this->context->buildViolation($constraint->impacts)->atPath('link_type')->addViolation(),
        'cycle' => $this->context->buildViolation($constraint->cycle)
          ->setParameter('%relation', (string) ($link->getLinkType()?->label() ?? $link->get('link_type')->value))
          ->atPath('link_type')
          ->addViolation(),
      };
    }
  }

}
