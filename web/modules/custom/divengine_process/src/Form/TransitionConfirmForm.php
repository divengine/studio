<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\divengine_process\Lifecycle\TransitionException;

/**
 * Confirms a transition that asks for confirmation before it applies.
 */
class TransitionConfirmForm extends ConfirmFormBase {

  protected EntityInterface $entity;

  protected string $transition;

  public function getFormId(): string {
    return 'divengine_process_transition_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $entity = NULL, string $transition = ''): array {
    $this->entity = $entity;
    $this->transition = $transition;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    $transition = $this->entity::transitions()[$this->transition];
    return $transition->confirm ?? $this->t('@transition %label?', ['@transition' => $transition->label, '%label' => $this->entity->label()]);
  }

  public function getConfirmText() {
    return $this->entity::transitions()[$this->transition]->label;
  }

  public function getCancelUrl(): Url {
    $development = $this->entity->hasField('development') ? $this->entity->get('development')->entity : NULL;
    return ($development ?? $this->entity)->toUrl();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      \Drupal::service('divengine_process.lifecycle')->apply($this->entity, $this->transition);
    }
    catch (TransitionException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
