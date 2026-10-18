<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\QaException;
use Drupal\divengine_qa\QaResultRecorder;
use Drupal\divengine_qa\QaVocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records, corrects or bulk-applies a result ("Record QA Result").
 *
 * A result button opens it with the result fixed (?state=failed); Correct
 * Result opens it with a selector; Block/Skip Remaining Steps opens it in
 * bulk mode (?bulk=1). Only the fields the chosen result needs are asked.
 */
class RecordResultForm extends FormBase {

  protected QaTestExecution|QaStepResult $record;

  protected ?string $quickState = NULL;

  protected bool $bulk = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QaResultRecorder $recorder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('divengine_qa.results'));
  }

  public function getFormId(): string {
    return 'divengine_qa_record_result';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $type = '', string $id = ''): array {
    $this->record = $this->entityTypeManager->getStorage($type === 'step' ? 'qa_step_result' : 'qa_test_execution')->load($id);
    $request = $this->getRequest()->query;
    $this->quickState = isset(QaVocabulary::results()[$request->get('state', '')]) ? $request->get('state') : NULL;
    $this->bulk = (bool) $request->get('bulk') && $this->record instanceof QaTestExecution;
    $current = $this->record->getResult();
    $results = array_map('strval', QaVocabulary::results());

    $form['#title'] = $this->title();
    $form['subject'] = [
      '#type' => 'item',
      '#title' => $this->record instanceof QaStepResult ? $this->t('Step') : $this->t('Test Execution'),
      '#plain_text' => $this->record->label(),
    ];
    if ($this->record instanceof QaStepResult && $this->record->get('expected_result')->value) {
      $form['expected'] = ['#type' => 'item', '#title' => $this->t('Expected Result'), '#plain_text' => $this->record->get('expected_result')->value];
    }
    $form['current'] = ['#type' => 'item', '#title' => $this->t('Current Result'), '#plain_text' => $results[$current]];
    if ($this->bulk) {
      $pending = count(array_filter($this->record->getStepResults(), fn ($s) => $s->getResult() === 'pending'));
      $form['pending'] = ['#type' => 'item', '#title' => $this->t('Pending Steps'), '#plain_text' => (string) $pending];
    }

    if ($this->quickState) {
      $form['target_state'] = ['#type' => 'value', '#value' => $this->quickState];
    }
    else {
      $form['target_state'] = [
        '#type' => 'radios',
        '#title' => $this->t('New Result'),
        '#options' => $results,
        '#default_value' => $current,
        '#required' => TRUE,
      ];
    }
    $form['guidance'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->guidance(),
      '#attributes' => ['class' => ['dv-intro']],
    ];

    $state = fn (string ...$values) => ['visible' => [':input[name="target_state"]' => array_map(fn ($v) => ['value' => $v], $values)]];
    $target = $this->quickState;
    if (!$this->bulk) {
      $form['actual_result'] = [
        '#type' => 'textarea',
        '#title' => $target === 'failed' ? $this->t('Observed Behavior') : ($target === 'blocked' ? $this->t('Actual Result (optional)') : $this->t('Actual Result')),
        '#default_value' => $this->record->get('actual_result')->value,
        '#rows' => 3,
        '#access' => !in_array($target, ['skipped', 'passed', 'pending'], TRUE),
      ];
      if (!$target) {
        $form['actual_result']['#states'] = $state('failed', 'blocked');
      }
    }
    $form['result_reason'] = [
      '#type' => 'textarea',
      '#title' => $target === 'skipped' ? $this->t('Why was it not executed?') : $this->t('Why could it not be executed?'),
      '#default_value' => $this->bulk ? '' : $this->record->get('result_reason')->value,
      '#rows' => 3,
      '#access' => !$target || in_array($target, ['blocked', 'skipped'], TRUE),
    ];
    if (!$target) {
      $form['result_reason']['#states'] = $state('blocked', 'skipped');
    }
    if (!$this->bulk && $target === 'failed') {
      $form['comments'] = ['#type' => 'textarea', '#title' => $this->t('Notes (optional)'), '#default_value' => $this->record->get('comments')->value, '#rows' => 2];
    }
    if (!$this->bulk && $current !== 'pending' && $target !== $current) {
      $form['correction_reason'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Correction Reason'),
        '#description' => $this->t("The previous result, the new result, your name, the date and this reason are stored in the execution's correction history."),
        '#rows' => 2,
        '#required' => (bool) $target,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save Result'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $target = (string) $form_state->getValue('target_state');
    if ($this->quickState && $target !== $this->quickState) {
      $this->messenger()->addError($this->t('This dialog records the result chosen with the button (@label). Use Correct Result to choose another result.', ['@label' => QaVocabulary::results()[$this->quickState]]));
      return;
    }
    try {
      if ($this->bulk) {
        $this->recorder->recordRemaining($this->record, $target, (string) $form_state->getValue('result_reason'));
      }
      else {
        $values = [];
        foreach (['actual_result', 'result_reason', 'comments'] as $field) {
          if ($form_state->hasValue($field) && ($form[$field]['#access'] ?? TRUE)) {
            $values[$field] = trim((string) $form_state->getValue($field));
          }
        }
        $this->recorder->record($this->record, $target, $values, $form_state->getValue('correction_reason'));
      }
      $this->messenger()->addStatus($this->t('Result saved.'));
      $execution = $this->record instanceof QaStepResult ? $this->record->get('execution')->entity : $this->record;
      $form_state->setRedirectUrl($execution->toUrl());
    }
    catch (QaException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
    }
  }

  protected function title(): string {
    $step = $this->record instanceof QaStepResult;
    if ($this->bulk) {
      return (string) ($this->quickState === 'skipped' ? $this->t('Skip Remaining Steps') : $this->t('Block Remaining Steps'));
    }
    return (string) match ($this->quickState) {
      'passed' => $step ? $this->t('Mark Step as Passed') : $this->t('Mark Execution as Passed'),
      'failed' => $step ? $this->t('Mark Step as Failed') : $this->t('Mark Execution as Failed'),
      'blocked' => $step ? $this->t('Mark Step as Blocked') : $this->t('Mark Execution as Blocked'),
      'skipped' => $step ? $this->t('Skip Optional Step') : $this->t('Skip Execution'),
      'pending' => $step ? $this->t('Reset Step to Pending') : $this->t('Reset Execution to Pending'),
      default => $this->record->getResult() === 'pending' ? $this->t('Record Result') : $this->t('Correct Result'),
    };
  }

  protected function guidance(): string {
    $state = $this->quickState ?? $this->record->getResult();
    $text = (string) match ($state) {
      'pending' => $this->t('Clears the recorded result. Use it only to undo an accidental entry; the previous result and your reason stay in the correction history.'),
      'passed' => $this->t('The system behaved exactly as the expected result describes.'),
      'failed' => $this->t('The observed behavior differs from the expected result. Describe what actually happened so the issue can be reproduced.'),
      'blocked' => $this->t('The step could not be executed because of something outside the test (environment, data, access or an earlier defect). Explain what prevented execution.'),
      default => $this->t('The step was intentionally not executed. Only steps marked Optional by the test designer can be Skipped; a mandatory step whose prerequisite is missing is Blocked.'),
    };
    if ($state === 'skipped' && $this->record instanceof QaStepResult) {
      $text .= ' ' . ($this->record->isOptional() ? $this->t('This step is Optional.') : $this->t('This step is mandatory and cannot be Skipped.'));
    }
    if ($this->bulk) {
      $pending = count(array_filter($this->record->getStepResults(), fn ($s) => $s->getResult() === 'pending'));
      $text .= ' ' . $this->formatPlural($pending, 'The result will be applied to 1 pending step.', 'The result will be applied to @count pending steps.');
    }
    return $text;
  }

}
