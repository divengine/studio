<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\TestUrl;

/**
 * A Tester creates a Draft run for themselves from an Active plan.
 */
class CreateMyRunForm extends QaWizardBase {

  public function getFormId(): string {
    return 'divengine_qa_create_my_run';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#title'] = $this->t('Create My Test Run');
    $plans = $this->entityTypeManager->getStorage('qa_test_plan');
    $ids = $plans->getQuery()->accessCheck(TRUE)->condition('state', 'active')->sort('name')->execute();
    $options = array_map(fn ($plan) => $plan->label(), $plans->loadMultiple($ids));
    $selected = $form_state->getValue('plan') ?: $this->getRequest()->query->get('plan');
    $plan = $selected && isset($options[$selected]) ? $plans->load($selected) : NULL;
    $form['plan'] = [
      '#type' => 'select',
      '#title' => $this->t('Test Plan'),
      '#options' => $options,
      '#empty_option' => $this->t('- Choose an Active Test Plan -'),
      '#default_value' => $plan?->id(),
      '#required' => TRUE,
      '#ajax' => ['callback' => '::refresh', 'wrapper' => 'dv-my-run'],
    ];
    $form['details'] = ['#type' => 'container', '#attributes' => ['id' => 'dv-my-run']];
    if ($plan) {
      $ready = $this->metrics->plan($plan)['ready_cases'];
      $form['details']['facts'] = [
        '#type' => 'item',
        '#markup' => (string) $this->t('Development: %development · Environment: %env · Stage: %stage · Ready Test Cases: @ready', [
          '%development' => $plan->get('development')->entity?->label(),
          '%env' => $plan->get('environment')->entity?->label(),
          '%stage' => $plan->get('stage')->entity?->label(),
          '@ready' => $ready,
        ]),
      ];
      if (!$ready) {
        $form['details']['warning'] = ['#theme' => 'status_messages', '#message_list' => ['warning' => [$this->t('This Test Plan has no Ready Test Cases, so a Run cannot be created from it. Ask your QA Lead to prepare the Plan.')]], '#status_headings' => ['warning' => $this->t('Warning')]];
      }
      $form['details']['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Run Name'),
        '#default_value' => $plan->get('name')->value . ' - ' . $this->user->getDisplayName() . ' - ' . date('Y-m-d'),
      ];
      $form['details']['test_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Test URL'),
        '#maxlength' => 2048,
        '#default_value' => $plan->get('environment')->entity?->getDefaultTestUrl(),
      ];
    }
    $form['actions'] = $this->submitButton((string) $this->t('Create Test Run'));
    return $form;
  }

  public function refresh(array $form): array {
    return $form['details'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = TestUrl::normalize($form_state->getValue('test_url'));
    if (!TestUrl::isValid($url)) {
      $form_state->setErrorByName('test_url', $this->t("The Test URL must be a complete http:// or https:// address, for example https://test.example.com (got '@value').", ['@value' => $url]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $plan = $this->entityTypeManager->getStorage('qa_test_plan')->load($form_state->getValue('plan'));
    if (!$plan) {
      $this->messenger()->addError($this->t('Select the Test Plan of the new Test Run.'));
      return;
    }
    $run = $this->attempt($form_state, fn () => $this->runs->createSelfServiceRun($plan, (string) $form_state->getValue('name'), TestUrl::normalize($form_state->getValue('test_url'))));
    if ($run) {
      $form_state->setRedirectUrl($run->toUrl());
    }
  }

}
