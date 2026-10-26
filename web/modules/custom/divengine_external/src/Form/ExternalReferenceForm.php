<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;

/**
 * Registers external work, or changes its label and notes.
 *
 * The link and the Development are given once; "Register external work" in
 * a Development passes ?development=ID.
 */
class ExternalReferenceForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\divengine_external\Entity\ExternalReference $entity */
    $entity = $this->entity;
    if ($entity->isNew() && ($id = $this->getRequest()->query->get('development')) && ($development = $this->entityTypeManager->getStorage('development')->load($id)) && $development->access('update')) {
      $entity->set('development', $development->id());
    }
    $form = parent::form($form, $form_state);
    if (!$entity->isNew() || !$entity->get('development')->isEmpty()) {
      $form['development']['#access'] = FALSE;
    }
    if (!$entity->isNew()) {
      $form['url']['#access'] = FALSE;
    }
    EntityFormLayout::apply($form, $entity, ['development'], ['notes']);
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($result === SAVED_NEW ? $this->t('Registered %label.', ['%label' => $this->entity->label()]) : $this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
