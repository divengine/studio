<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for Developments: what it is on the left, how it is run on the right.
 *
 * The code, state, current phase and iteration and the process snapshot are
 * not edited here: they are assigned, changed by transitions or applied from
 * the Development Case.
 */
class DevelopmentForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\DevelopmentInterface $development */
    $development = $this->entity;

    $form['#attributes']['class'][] = 'dv-entity-form';
    $form['side'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-entity-form__side']],
      '#weight' => 90,
    ];
    $form['side']['people'] = [
      '#type' => 'details',
      '#title' => $this->t('People'),
      '#open' => TRUE,
    ];
    $form['side']['schedule'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule'),
      '#open' => TRUE,
    ];
    $form['side']['process'] = [
      '#type' => 'details',
      '#title' => $this->t('Process'),
      '#open' => TRUE,
    ];
    $groups = [
      'responsible' => 'people',
      'members' => 'people',
      'date_start' => 'schedule',
      'date_end' => 'schedule',
      'case' => 'process',
      'status' => 'process',
    ];
    foreach ($groups as $field_name => $group) {
      if (isset($form[$field_name])) {
        $form['side'][$group][$field_name] = $form[$field_name];
        unset($form[$field_name]);
      }
    }
    if (isset($form['revision_information'])) {
      $form['revision_information']['#group'] = NULL;
      $form['side']['revision_information'] = $form['revision_information'];
      $form['side']['revision_information']['#type'] = 'details';
      $form['side']['revision_information']['#open'] = FALSE;
      unset($form['revision_information']);
    }
    if (!$development->isNew() && isset($form['side']['process']['case'])) {
      $form['side']['process']['case']['widget']['#description'] = $this->t('Changing the case takes effect when you apply it from the Development page.');
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created %label with its phases, milestones and process.', $args)
      : $this->t('Saved %label.', $args));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
