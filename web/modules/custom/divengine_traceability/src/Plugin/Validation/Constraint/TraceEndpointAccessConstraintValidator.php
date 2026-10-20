<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\divengine_traceability\Entity\TraceLink;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class TraceEndpointAccessConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected AccountProxyInterface $currentUser) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('current_user'));
  }

  public function validate(mixed $link, Constraint $constraint): void {
    if (!$link instanceof TraceLink || !$link->endsChanged()) {
      return;
    }
    // A missing end is reported by ValidTraceLink.
    foreach ([$link->getSourceEntity(), $link->getTargetEntity()] as $end) {
      if ($end && !$end->access('view', $this->currentUser)) {
        $this->context->buildViolation($constraint->message)->addViolation();
        return;
      }
    }
  }

}
