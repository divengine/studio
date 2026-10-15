<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Exception;

/**
 * Thrown when a model sent by an editor cannot be saved.
 */
class InvalidModelException extends \RuntimeException {

  /**
   * @param string[] $errors
   *   The reasons, safe to show to the user.
   */
  public function __construct(protected array $errors) {
    parent::__construct(implode(' ', $errors));
  }

  /**
   * @return string[]
   */
  public function getErrors(): array {
    return $this->errors;
  }

}
