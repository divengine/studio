<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for Developments.
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
    if (!$development->isNew() && isset($form['case'])) {
      $form['case']['widget']['#description'] = $this->t('Changing the case takes effect when you apply it from the Development page.');
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created %label.', $args)
      : $this->t('Saved %label.', $args));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
