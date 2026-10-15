<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Edits a diagram's details; the drawing itself is edited in the modeler.
 */
class DiagramForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_modeler\Entity\DiagramInterface $diagram */
    $diagram = $this->entity;
    if (isset($form['source'])) {
      $notation = $diagram->getNotation();
      $form['source']['#type'] = 'details';
      $form['source']['#title'] = $this->t('Source (@format)', ['@format' => strtoupper($notation->sourceFormat())]);
      $form['source']['#open'] = $diagram->isNew() ? FALSE : $notation->family() === 'text';
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $is_new = $this->entity->isNew();
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the diagram %label.', ['%label' => $this->entity->label()]));
    // A new diagram is drawn next.
    $form_state->setRedirect($is_new && $this->entity->access('update') ? 'entity.diagram.modeler' : 'entity.diagram.canonical', ['diagram' => $this->entity->id()]);
    return $result;
  }

}
