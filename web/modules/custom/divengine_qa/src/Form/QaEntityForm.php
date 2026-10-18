<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;

/**
 * Form for the QA design records: plans, suites, cases and steps.
 *
 * Opened from a parent ("Add case" on a suite passes ?suite=ID), the parent
 * is filled in and not shown; parent choices are limited to the same plan.
 * Saving returns to the parent.
 */
class QaEntityForm extends ContentEntityForm {

  /**
   * The parent reference of each record and the query key that sets it.
   */
  const PARENTS = [
    'qa_test_plan' => ['development', 'development'],
    'qa_test_suite' => ['plan', 'plan'],
    'qa_test_case' => ['suite', 'suite'],
    'qa_test_step' => ['case', 'case'],
  ];

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->entity;
    [$field, $key] = static::PARENTS[$entity->getEntityTypeId()] ?? [NULL, NULL];
    if ($field && $entity->isNew() && $entity->get($field)->isEmpty() && ($id = $this->getRequest()->query->get($key))) {
      $target = $this->entityTypeManager->getStorage($entity->getFieldDefinition($field)->getSetting('target_type'))->load($id);
      if ($target && $target->access($entity->getEntityTypeId() === 'qa_test_plan' ? 'view' : 'update')) {
        $entity->set($field, $target->id());
      }
    }
    // A suite created inside another suite.
    if ($entity->getEntityTypeId() === 'qa_test_suite' && $entity->isNew() && ($parent = $this->getRequest()->query->get('parent'))) {
      $parent = $this->entityTypeManager->getStorage('qa_test_suite')->load($parent);
      if ($parent) {
        $entity->set('parent', $parent->id());
        $entity->set('plan', $parent->get('plan')->target_id);
      }
    }
    $form = parent::form($form, $form_state);

    if ($field && !$entity->get($field)->isEmpty() && isset($form[$field])) {
      $form[$field]['#access'] = FALSE;
    }
    $this->limitToPlan($form);
    EntityFormLayout::apply($form, $entity, ['uid', 'responsible', 'is_skippable'], ['tags']);
    return $form;
  }

  /**
   * Suite choices are limited to the suites of the record's plan.
   */
  protected function limitToPlan(array &$form): void {
    $entity = $this->entity;
    $plan = match ($entity->getEntityTypeId()) {
      'qa_test_suite' => $entity->get('plan')->target_id,
      'qa_test_case' => $entity->get('suite')->entity?->get('plan')->target_id ?? $entity->get('plan')->target_id,
      default => NULL,
    };
    foreach (['parent', 'suite'] as $field) {
      if (!$plan || !isset($form[$field]['widget']['#options'])) {
        continue;
      }
      $ids = $this->entityTypeManager->getStorage('qa_test_suite')->getQuery()
        ->accessCheck(TRUE)
        ->condition('plan', $plan)
        ->execute();
      unset($ids[$entity->id()]);
      $form[$field]['widget']['#options'] = array_intersect_key($form[$field]['widget']['#options'], ['_none' => TRUE] + $ids);
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW ? $this->t('Created %label.', $args) : $this->t('Saved %label.', $args));
    [$field] = static::PARENTS[$this->entity->getEntityTypeId()] ?? [NULL];
    $parent = $field && $this->entity->getEntityTypeId() === 'qa_test_step' ? $this->entity->get($field)->entity : NULL;
    $form_state->setRedirectUrl(($parent ?? $this->entity)->toUrl());
    return $result;
  }

}
