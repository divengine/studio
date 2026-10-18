<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for process configuration: name, code, description, order, status.
 *
 * Subclasses add the fields of their entity type.
 */
class ProcessConfigForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\ProcessConfigEntityBase $entity */
    $entity = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Code'),
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [$entity::class, 'load'],
      ],
      '#disabled' => !$entity->isNew(),
      '#description' => $this->t('Stable key; it never changes once saved.'),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->getDescription(),
      '#rows' => 3,
    ];
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Order'),
      '#default_value' => $entity->getWeight(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->status(),
      '#description' => $this->t('Disabled entries stay on existing records but are not offered for new ones.'),
      '#weight' => 100,
    ];
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created %label.', $args)
      : $this->t('Saved %label.', $args));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

  /**
   * Options for a reference to other process configuration.
   *
   * Enabled entities are offered; already selected disabled ones are kept so
   * saving the form does not drop them.
   *
   * @param string $entity_type_id
   *   The referenced config entity type.
   * @param string[] $selected
   *   The current values.
   *
   * @return array<string, string>
   */
  protected function referenceOptions(string $entity_type_id, array $selected = []): array {
    $entities = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple();
    uasort($entities, [$this->entityTypeManager->getDefinition($entity_type_id)->getClass(), 'sort']);
    $options = [];
    foreach ($entities as $id => $entity) {
      if ($entity->status() || in_array($id, $selected, TRUE)) {
        $options[$id] = $entity->label();
      }
    }
    return $options;
  }

}
