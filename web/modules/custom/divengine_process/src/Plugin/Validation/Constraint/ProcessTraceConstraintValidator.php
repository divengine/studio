<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\divengine_traceability\Entity\TraceLinkInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessTrace constraint.
 */
class ProcessTraceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Relations whose graph must stay acyclic (extend may point back).
   */
  const ACYCLIC = ['includes', 'generalizes'];

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $link, Constraint $constraint): void {
    if (!$link instanceof TraceLinkInterface) {
      return;
    }
    /** @var \Drupal\divengine_process\Plugin\Validation\Constraint\ProcessTraceConstraint $constraint */
    $source = $link->getSourceEntity();
    $target = $link->getTargetEntity();
    if (!$source || !$target) {
      return;
    }
    $source_development = $this->development($source);
    $target_development = $this->development($target);
    if ($source_development && $target_development && $source_development !== $target_development) {
      $this->context->buildViolation($constraint->crossDevelopment)->addViolation();
    }

    $type = (string) $link->get('link_type')->value;
    if ($type === 'impacts' && ($source_development || $target_development) && $source->getEntityTypeId() !== 'change_request') {
      $this->context->buildViolation($constraint->impacts)->atPath('link_type')->addViolation();
    }

    if (in_array($type, static::ACYCLIC, TRUE) && $this->reaches($target, $source, $type, $link->id())) {
      $this->context->buildViolation($constraint->cycle)
        ->setParameter('%relation', (string) ($link->getLinkType()?->label() ?? $type))
        ->atPath('link_type')
        ->addViolation();
    }
  }

  /**
   * The Development a record belongs to, if any.
   */
  protected function development(EntityInterface $entity): ?string {
    if ($entity->getEntityTypeId() === 'development') {
      return (string) $entity->id();
    }
    if ($entity instanceof FieldableEntityInterface && $entity->hasField('development') && !$entity->get('development')->isEmpty()) {
      return (string) $entity->get('development')->target_id;
    }
    return NULL;
  }

  /**
   * Whether $from already reaches $to through links of the same type.
   */
  protected function reaches(EntityInterface $from, EntityInterface $to, string $type, int|string|null $exclude): bool {
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $goal = $to->getEntityTypeId() . ':' . $to->id();
    $frontier = [$from->getEntityTypeId() . ':' . $from->id()];
    $seen = [];
    while ($frontier) {
      $key = array_shift($frontier);
      if ($key === $goal) {
        return TRUE;
      }
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      [$entity_type, $id] = explode(':', $key, 2);
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('link_type', $type)
        ->condition('source_entity_type', $entity_type)
        ->condition('source_entity_id', $id);
      if ($exclude) {
        $query->condition('id', $exclude, '<>');
      }
      foreach ($storage->loadMultiple($query->execute()) as $next) {
        $frontier[] = $next->getTargetEntityType() . ':' . $next->getTargetEntityId();
      }
    }
    return FALSE;
  }

}
