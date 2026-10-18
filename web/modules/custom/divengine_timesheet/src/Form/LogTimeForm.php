<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_timesheet\Entity\Timesheet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Log time" on a task page, in one line: date, hours, what you did.
 *
 * When a timer was just stopped the hours come prefilled.
 */
class LogTimeForm extends FormBase {

  protected ?ProjectTask $task = NULL;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'divengine_timesheet_log_time';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?ProjectTask $task = NULL, ?float $hours = NULL): array {
    $this->task = $task;
    $form['#attributes']['class'][] = 'dv-log-time';
    $form['#attributes']['id'] = 'log-time';
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#default_value' => date('Y-m-d', \Drupal::time()->getRequestTime()),
      '#required' => TRUE,
    ];
    $form['hours'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hours'),
      '#size' => 6,
      '#default_value' => $hours ? Timesheet::formatHours($hours) : '',
      '#placeholder' => '1:30',
      '#required' => TRUE,
    ];
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What did you do?'),
      '#maxlength' => 255,
      '#placeholder' => $this->t('e.g. Wrote the email template'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Log time'), '#button_type' => 'primary'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $hours = Timesheet::parseHours((string) $form_state->getValue('hours'));
    if ($hours === NULL || $hours <= 0 || $hours > 24) {
      $form_state->setErrorByName('hours', $this->t('Enter the time spent as 1.5 or 1:30, more than 0 and at most 24 hours.'));
      return;
    }
    $form_state->setValue('hours', $hours);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\divengine_timesheet\Entity\Timesheet $entry */
    $entry = $this->entityTypeManager->getStorage('timesheet')->create([
      'uid' => $this->currentUser()->id(),
      'date' => $form_state->getValue('date'),
      'task' => $this->task->id(),
      'project' => $this->task->get('project')->target_id,
      'description' => $form_state->getValue('description'),
      'hours' => $form_state->getValue('hours'),
    ]);
    $violations = $entry->validate();
    if ($violations->count()) {
      $this->messenger()->addError($violations[0]->getMessage());
      return;
    }
    $entry->save();
    $this->messenger()->addStatus($this->t('Logged %hours on %task.', ['%hours' => Timesheet::formatHours($entry->getHours()), '%task' => $this->task->label()]));
    $form_state->setRedirectUrl($this->task->toUrl());
  }

}
