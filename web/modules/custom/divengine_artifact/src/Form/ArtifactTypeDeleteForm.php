<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;

class ArtifactTypeDeleteForm extends EntityDeleteForm {

  public function getDescription(): string {
    return $this->t('Delete the %label artifact type? This action cannot be undone.', [
      '%label' => $this->entity->label(),
    ]);
  }

}
