<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestPlan;

/**
 * Clones a plan for another environment or stage.
 */
class ClonePlanForm extends QaWizardBase {

  protected QaTestPlan $plan;

  public function getFormId(): string {
    return 'divengine_qa_clone_plan';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestPlan $qa_test_plan = NULL): array {
    $this->plan = $qa_test_plan;
    $form['#title'] = $this->t('Clone @plan', ['@plan' => $this->plan->label()]);
    $form['intro'] = $this->intro((string) $this->t('The suites, cases and steps are copied into a new Draft plan; runs, results and QA Issues are not.'));
    $form['name'] = ['#type' => 'textfield', '#title' => $this->t('New Test Plan Name'), '#required' => TRUE, '#default_value' => $this->plan->get('name')->value . ' (Copy)'];
    $form['environment'] = ['#type' => 'select', '#title' => $this->t('Target Environment'), '#required' => TRUE, '#options' => $this->options('qa_environment'), '#default_value' => $this->plan->get('environment')->target_id];
    $form['stage'] = ['#type' => 'select', '#title' => $this->t('Target Stage'), '#required' => TRUE, '#options' => $this->options('qa_stage'), '#default_value' => $this->plan->get('stage')->target_id];
    $form['actions'] = $this->submitButton((string) $this->t('Clone'));
    return $form;
  }

  protected function options(string $entity_type): array {
    $entities = array_filter($this->entityTypeManager->getStorage($entity_type)->loadMultiple(), fn ($e) => $e->status());
    uasort($entities, [$this->entityTypeManager->getDefinition($entity_type)->getClass(), 'sort']);
    return array_map(fn ($e) => $e->label(), $entities);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $copy = $this->attempt($form_state, fn () => $this->design->clonePlan($this->plan, (string) $form_state->getValue('environment'), (string) $form_state->getValue('stage'), (string) $form_state->getValue('name')));
    if ($copy) {
      $this->messenger()->addStatus($this->t('Cloned into %plan.', ['%plan' => $copy->label()]));
      $form_state->setRedirectUrl($copy->toUrl());
    }
  }

}
