<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding and editing trace links.
 *
 * The two ends are stored as (entity type, id) pairs, so they get their own
 * autocomplete elements; every other field renders through its widget.
 * Validation is the ValidTraceLink entity constraint.
 */
class TraceLinkForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkInterface $link */
    $link = $this->entity;

    foreach (['source' => $link->getSourceEntity(), 'target' => $link->getTargetEntity()] as $end => $entity) {
      $type = $end === 'source' ? $link->getSourceEntityType() : $link->getTargetEntityType();
      if ($type && $type !== 'artifact') {
        $this->messenger()->addWarning($this->t('The @end is a "@type" entity; this form only edits links between artifacts.', [
          '@end' => $end,
          '@type' => $type,
        ]));
      }
      $form[$end . '_artifact'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $end === 'source' ? $this->t('Source artifact') : $this->t('Target artifact'),
        '#target_type' => 'artifact',
        '#required' => TRUE,
        '#default_value' => $type === 'artifact' ? $entity : NULL,
        '#weight' => $end === 'source' ? -20 : -15,
      ];
    }
    return $form;
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    foreach (['source', 'target'] as $end) {
      $value = $form_state->getValue($end . '_artifact');
      if ($value !== NULL && $value !== '') {
        $entity->set($end . '_entity_type', 'artifact');
        $entity->set($end . '_entity_id', (int) (is_object($value) ? $value->id() : $value));
      }
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the trace link %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
