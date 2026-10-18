<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\divengine_source\Entity\SourceRepository;
use Drupal\divengine_source\Git\GitException;
use Drupal\divengine_source\Git\Roots;
use Drupal\divengine_source\SourceGit;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SourceRepositoryPath constraint.
 */
class SourceRepositoryPathConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected SourceGit $git) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_source.git'));
  }

  public function validate(mixed $repository, Constraint $constraint): void {
    if (!$repository instanceof SourceRepository) {
      return;
    }
    /** @var \Drupal\divengine_source\Plugin\Validation\Constraint\SourceRepositoryPathConstraint $constraint */
    $path = rtrim(trim((string) $repository->get('path')->value), '/');
    $original = $repository->isNew() ? NULL : $repository->getOriginal() ?? \Drupal::entityTypeManager()->getStorage('source_repository')->loadUnchanged($repository->id());
    $changed = !$original || rtrim((string) $original->get('path')->value, '/') !== $path;
    if (!$changed) {
      return;
    }
    if ($original && $repository->hasHistory()) {
      $this->context->buildViolation($constraint->frozen)->setParameter('%path', (string) $original->get('path')->value)->atPath('path')->addViolation();
      return;
    }
    try {
      Roots::resolve($path, $this->git->roots());
    }
    catch (GitException $e) {
      $this->context->buildViolation($constraint->notAllowed)
        ->setParameter('%path', $path)
        ->setParameter('@reason', $e->getMessage())
        ->setParameter('@roots', implode(', ', $this->git->roots()) ?: '-')
        ->atPath('path')
        ->addViolation();
    }
  }

}
