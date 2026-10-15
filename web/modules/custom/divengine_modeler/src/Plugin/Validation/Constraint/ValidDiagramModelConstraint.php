<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks a diagram's source, preview and element bindings.
 */
#[Constraint(
  id: 'ValidDiagramModel',
  label: new TranslatableMarkup('Valid diagram model', [], ['context' => 'Validation']),
  type: 'entity:diagram',
)]
class ValidDiagramModelConstraint extends SymfonyConstraint {

  /**
   * Largest number of element bindings.
   */
  const MAX_BINDINGS = 2000;

}
