<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

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

    // What the record says on the left; how it is classified, planned and
    // owned on the right: single-value choices and references, the owner,
    // the state and the revision information.
    $form['#attributes']['class'][] = 'dv-entity-form';
    $form['side'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-entity-form__side']],
      '#weight' => 90,
      'properties' => ['#type' => 'details', '#title' => $this->t('Properties'), '#open' => TRUE],
    ];
    foreach ($artifact->getFieldDefinitions() as $name => $definition) {
      if (!isset($form[$name]) || ($form[$name]['#access'] ?? TRUE) === FALSE) {
        continue;
      }
      $single_choice = $definition->getFieldStorageDefinition()->getCardinality() === 1
        && in_array($definition->getType(), ['list_string', 'entity_reference', 'boolean'], TRUE);
      if ($single_choice || in_array($name, ['uid', 'status', 'moderation_state'], TRUE)) {
        $form['side']['properties'][$name] = $form[$name];
        unset($form[$name]);
      }
    }
    if (isset($form['revision_information'])) {
      $form['revision_information']['#group'] = NULL;
      $form['side']['revision_information'] = ['#type' => 'details', '#open' => FALSE] + $form['revision_information'];
      unset($form['revision_information']);
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label artifact.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
