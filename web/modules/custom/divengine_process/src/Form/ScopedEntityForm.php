<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for records of a Development (iterations, role assignments…).
 *
 * Opened from a Development (?development=ID), the record is attached to it
 * and the Development field is not shown; references to phases are limited
 * to that Development's phases. Saving returns to the Development.
 */
class ScopedEntityForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->entity;
    if ($entity->isNew() && $entity->get('development')->isEmpty() && ($id = $this->getRequest()->query->get('development'))) {
      $development = $this->entityTypeManager->getStorage('development')->load($id);
      if ($development && $development->access('update')) {
        $entity->set('development', $development->id());
      }
    }
    $form = parent::form($form, $form_state);

    $development_id = $entity->get('development')->target_id;
    if ($development_id) {
      $form['development']['#access'] = FALSE;
      foreach (['phase'] as $field_name) {
        if (isset($form[$field_name]['widget']['#options'])) {
          $phases = $this->entityTypeManager->getStorage('development_phase')->loadByProperties(['development' => $development_id]);
          $form[$field_name]['widget']['#options'] = array_intersect_key(
            $form[$field_name]['widget']['#options'],
            ['_none' => TRUE] + $phases,
          );
        }
      }
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created %label.', $args)
      : $this->t('Saved %label.', $args));
    $development = $this->entity->get('development')->entity;
    $form_state->setRedirectUrl($development ? $development->toUrl() : $this->entity->toUrl());
    return $result;
  }

}
