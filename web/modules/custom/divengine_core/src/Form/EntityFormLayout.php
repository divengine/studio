<?php

declare(strict_types=1);

namespace Drupal\divengine_core\Form;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Two-column layout of Studio entity forms.
 *
 * What the record says stays on the left; how it is classified, planned and
 * owned goes to a side panel: single-value choices and references, owners,
 * states and the revision information. Field widgets keep their #parents,
 * so moving them does not change the submitted values.
 */
final class EntityFormLayout {

  /**
   * Moves the side fields of an entity form into the side panel.
   *
   * @param array $form
   *   The built entity form.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity of the form.
   * @param string[] $always_side
   *   Fields that go to the side whatever their type.
   * @param string[] $always_main
   *   Fields that stay in the main column whatever their type.
   */
  public static function apply(array &$form, FieldableEntityInterface $entity, array $always_side = ['uid', 'status', 'moderation_state'], array $always_main = []): void {
    $form['#attributes']['class'][] = 'dv-entity-form';
    $form['side'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-entity-form__side']],
      '#weight' => 90,
      'properties' => [
        '#type' => 'details',
        '#title' => new TranslatableMarkup('Properties'),
        '#open' => TRUE,
      ],
    ];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!isset($form[$name]) || ($form[$name]['#access'] ?? TRUE) === FALSE || in_array($name, $always_main, TRUE)) {
        continue;
      }
      $storage = $definition->getFieldStorageDefinition();
      $single_choice = $storage->getCardinality() === 1
        && in_array($definition->getType(), ['list_string', 'entity_reference', 'boolean', 'datetime', 'link'], TRUE);
      if ($single_choice || in_array($name, $always_side, TRUE)) {
        $form['side']['properties'][$name] = $form[$name];
        unset($form[$name]);
      }
    }
    if (isset($form['revision_information'])) {
      $form['revision_information']['#group'] = NULL;
      $form['side']['revision_information'] = ['#type' => 'details', '#open' => FALSE] + $form['revision_information'];
      unset($form['revision_information']);
    }
  }

}
