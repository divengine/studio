<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_process\BaselineRecorder;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Takes a baseline of a Development now.
 */
class TakeBaselineForm extends FormBase {

  protected DevelopmentInterface $development;

  public function __construct(protected BaselineRecorder $baselines) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_process.baselines'));
  }

  public function getFormId(): string {
    return 'divengine_process_take_baseline';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?DevelopmentInterface $development = NULL): array {
    $this->development = $development;
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('A baseline records the exact revision of every baselined record of the Development and of the traces between them, so this state can always be reproduced. Pending revisions are not included.'),
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#default_value' => $this->t('Baseline of @date', ['@date' => date('Y-m-d')]),
    ];
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows' => 3,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Take baseline'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $baseline = $this->baselines->take($this->development, (string) $form_state->getValue('name'), NULL, (string) $form_state->getValue('notes'));
    $this->messenger()->addStatus($this->t('Took %baseline.', ['%baseline' => $baseline->label()]));
    $form_state->setRedirectUrl($baseline->toUrl());
  }

}
