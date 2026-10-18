<?php

declare(strict_types=1);

namespace Drupal\divengine_articles\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the KnowledgeArticleParent constraint.
 */
class KnowledgeArticleParentConstraintValidator extends ConstraintValidator {

  public function validate(mixed $items, Constraint $constraint): void {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $items */
    $article = $items->getEntity();
    $parent = $items->entity;
    if (!$parent || $article->isNew()) {
      return;
    }
    $tree = \Drupal::service('divengine_articles.tree');
    if ((int) $parent->id() === (int) $article->id() || in_array((int) $parent->id(), $tree->descendantIds((int) $article->id()), TRUE)) {
      $this->context->addViolation($constraint->cycle, ['%parent' => $parent->label()]);
    }
  }

}
