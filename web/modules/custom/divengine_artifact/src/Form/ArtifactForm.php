<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;

/**
 * Form for adding and editing artifacts.
 *
 * Fields render through their form display widgets so values are saved by
 * ContentEntityForm; key trimming and uniqueness live in the entity
 * (preSave and the UniqueArtifactKey constraint).
 */
class ArtifactForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_artifact\Entity\ArtifactInterface $artifact */
    $artifact = $this->entity;
    // Keys of types with a prefix are assigned once and never typed.
    if (isset($form['artifact_key']) && $artifact->get('type')->entity?->getKeyPrefix()) {
      $form['artifact_key']['#access'] = FALSE;
    }

    EntityFormLayout::apply($form, $artifact);
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label artifact.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
