<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\QaException;
use Drupal\divengine_qa\QaIssues;
use Drupal\divengine_qa\QaVocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Create or Link QA Issue", opened from an execution or a failed step.
 */
class ReportIssueForm extends FormBase {

  protected QaTestExecution $execution;

  protected ?QaStepResult $step = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QaIssues $issues,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('divengine_qa.issues'));
  }

  public function getFormId(): string {
    return 'divengine_qa_report_issue';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestExecution $qa_test_execution = NULL): array {
    $this->execution = $qa_test_execution;
    $step_id = $this->getRequest()->query->get('step');
    $this->step = $step_id ? $this->entityTypeManager->getStorage('qa_step_result')->load($step_id) : NULL;
    try {
      $this->issues->checkTarget($this->execution, $this->step);
    }
    catch (QaException $e) {
      $this->messenger()->addError($e->getMessage());
      return $form;
    }
    $failed = $this->issues->primaryFailedStep($this->execution, $this->step);

    $form['#title'] = $this->t('Create or Link QA Issue');
    $form['context'] = [
      '#type' => 'component',
      '#component' => 'divengine_core:fact-list',
      '#props' => ['rows' => array_values(array_filter([
        ['label' => (string) $this->t('Development'), 'value' => (string) $this->execution->get('development')->entity?->label()],
        ['label' => (string) $this->t('Test Run'), 'value' => (string) $this->execution->get('run')->entity?->label()],
        ['label' => (string) $this->t('Test Execution'), 'value' => $this->execution->label()],
        $this->execution->get('test_case')->entity ? ['label' => (string) $this->t('Test Case'), 'value' => $this->execution->get('test_case')->entity->label()] : NULL,
        $failed ? ['label' => (string) $this->t('Failed Step Result'), 'value' => $failed->label()] : NULL,
      ]))],
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Action'),
      '#options' => ['create' => $this->t('Create a new QA Issue'), 'link' => $this->t('Link an existing QA Issue')],
      '#default_value' => 'create',
      '#attributes' => ['class' => ['container-inline']],
      '#required' => TRUE,
    ];
    $create = ['visible' => [':input[name="mode"]' => ['value' => 'create']]];
    $link = ['visible' => [':input[name="mode"]' => ['value' => 'link']]];

    if ($duplicates = $this->issues->duplicates($this->execution)) {
      $items = [];
      foreach ($duplicates as $issue) {
        $items[] = $issue->toLink()->toRenderable() + ['#suffix' => ' · ' . $issue->getStateLabel()];
      }
      $form['duplicates'] = [
        '#type' => 'container',
        '#states' => $create,
        'warning' => [
          '#theme' => 'status_messages',
          '#message_list' => ['warning' => [$this->t('Open QA Issues already exist for this Test Case. Check the list below and link the execution to one of them instead of creating a duplicate.')]],
          '#status_headings' => ['warning' => $this->t('Warning message')],
        ],
        'list' => ['#theme' => 'item_list', '#title' => $this->t('Possible Duplicates'), '#items' => $items],
      ];
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('QA Issue Title'),
      '#default_value' => $this->issues->defaultTitle($this->execution, $failed),
      '#maxlength' => 255,
      '#states' => $create + ['required' => [':input[name="mode"]' => ['value' => 'create']]],
    ];
    $form['priority'] = [
      '#type' => 'select',
      '#title' => $this->t('Priority'),
      '#options' => array_map('strval', QaVocabulary::issuePriorities()),
      '#default_value' => $this->issues->defaultPriority($this->execution),
      '#states' => $create,
    ];
    $form['description'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Description'),
      '#default_value' => $this->issues->defaultDescription($this->execution, $failed),
      '#format' => 'qa_issue',
      '#allowed_formats' => ['qa_issue'],
      '#rows' => 16,
      '#states' => $create,
    ];
    $form['existing'] = [
      '#type' => 'select',
      '#title' => $this->t('Existing QA Issue'),
      '#options' => $this->issues->linkableIssues($this->execution),
      '#empty_option' => $this->t('- Select -'),
      '#states' => $link + ['required' => [':input[name="mode"]' => ['value' => 'link']]],
    ];
    $evidence = $this->issues->relevantEvidence($this->execution, $failed);
    // Run evidence is private to the Run; a copy on the issue is shared with
    // the QA team of the Development. Copying is opt-in and says so.
    $form['copy_attachments'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Copy Attachments to QA Issue'),
      '#default_value' => FALSE,
      '#access' => (bool) $evidence,
      '#description' => $evidence
        ? $this->formatPlural(count($evidence), '1 evidence file: @names', '@count evidence files: @names', ['@names' => implode(', ', array_map(fn ($f) => $f->getFilename(), $evidence))])
        : NULL,
    ];
    if ($evidence) {
      $form['copy_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'text' => ['#markup' => $this->t('Copies on the QA Issue can be opened by every QA user of the Development %development, including people who cannot open this Test Run. Copy only evidence they may see. The originals stay private to the Run.', ['%development' => (string) $this->execution->get('development')->entity?->label()])],
        '#states' => ['visible' => [':input[name="copy_attachments"]' => ['checked' => TRUE]]],
      ];
    }
    else {
      $form['no_evidence'] = ['#markup' => '<p>' . $this->t('No evidence attached to this execution or its failed steps.') . '</p>'];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['create'] = ['#type' => 'submit', '#value' => $this->t('Create QA Issue'), '#button_type' => 'primary', '#states' => $create];
    $form['actions']['link'] = ['#type' => 'submit', '#value' => $this->t('Link QA Issue'), '#button_type' => 'primary', '#states' => $link];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $copy = (bool) $form_state->getValue('copy_attachments');
    try {
      if ($form_state->getValue('mode') === 'link') {
        $existing = $form_state->getValue('existing');
        $issue = $this->issues->link($this->execution, $this->step, $existing ? $this->entityTypeManager->getStorage('qa_issue')->load($existing) : NULL, $copy);
        $this->messenger()->addStatus($this->t('The execution was linked to @issue.', ['@issue' => $issue->label()]));
      }
      else {
        $description = $form_state->getValue('description');
        $issue = $this->issues->create($this->execution, $this->step, (string) $form_state->getValue('name'), (string) ($description['value'] ?? ''), (string) $form_state->getValue('priority'), $copy);
        $this->messenger()->addStatus($this->t('QA Issue @issue was created.', ['@issue' => $issue->label()]));
      }
      $form_state->setRedirectUrl($issue->toUrl());
    }
    catch (QaException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
    }
  }

}
