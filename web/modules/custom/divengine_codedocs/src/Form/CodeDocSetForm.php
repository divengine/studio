<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;

/**
 * Form of documentation sets. "Register documentation" in a Development
 * passes ?development=ID.
 */
class CodeDocSetForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\divengine_codedocs\Entity\CodeDocSet $entity */
    $entity = $this->entity;
    if ($entity->isNew() && ($id = $this->getRequest()->query->get('development')) && ($development = $this->entityTypeManager->getStorage('development')->load($id)) && $development->access('view')) {
      $entity->set('development', $development->id());
    }
    $form = parent::form($form, $form_state);
    if ($entity->isNew() && !$entity->get('development')->isEmpty()) {
      $form['development']['#access'] = FALSE;
    }
    if ($entity->hasHistory()) {
      $form['development']['#disabled'] = TRUE;
      $form['identifier']['widget'][0]['value']['#disabled'] = TRUE;
      $form['identifier']['widget'][0]['value']['#description'] = $this->t('Fixed: the snapshots of this set were published under it.');
    }
    EntityFormLayout::apply($form, $entity, ['development', 'language', 'repository', 'source_root'], ['description']);
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($result === SAVED_NEW ? $this->t('Registered %label.', ['%label' => $this->entity->label()]) : $this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
