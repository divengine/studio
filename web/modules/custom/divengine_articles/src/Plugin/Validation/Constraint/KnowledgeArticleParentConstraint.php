<?php

declare(strict_types=1);

namespace Drupal\divengine_articles\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The parent of an article is another article, never one of its own.
 */
#[Constraint(
  id: 'KnowledgeArticleParent',
  label: new TranslatableMarkup('Knowledge article parent', [], ['context' => 'Validation']),
)]
class KnowledgeArticleParentConstraint extends SymfonyConstraint {

  public string $cycle = '%parent cannot be the parent: it is this article or one of its sub-articles.';

  public function validatedBy(): string {
    return KnowledgeArticleParentConstraintValidator::class;
  }

}
