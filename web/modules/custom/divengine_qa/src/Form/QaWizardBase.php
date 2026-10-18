<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_qa\QaDesign;
use Drupal\divengine_qa\QaException;
use Drupal\divengine_qa\QaMetrics;
use Drupal\divengine_qa\QaResultRecorder;
use Drupal\divengine_qa\QaRunWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base of the QA wizards: the services and how refusals are shown.
 *
 * A refused action keeps the form with the rule's message, like Odoo's
 * wizards keep the dialog open.
 */
abstract class QaWizardBase extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $user,
    protected QaRunWorkflow $runs,
    protected QaResultRecorder $results,
    protected QaDesign $design,
    protected QaMetrics $metrics,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('divengine_qa.runs'),
      $container->get('divengine_qa.results'),
      $container->get('divengine_qa.design'),
      $container->get('divengine_qa.metrics'),
    );
  }

  /**
   * Runs an action; a refusal becomes an error on the form.
   */
  protected function attempt(FormStateInterface $form_state, callable $action): mixed {
    try {
      return $action();
    }
    catch (QaException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return NULL;
    }
  }

  protected function intro(string $text): array {
    return ['#type' => 'html_tag', '#tag' => 'p', '#value' => $text, '#attributes' => ['class' => ['dv-intro']], '#weight' => -100];
  }

  protected function submitButton(string $label): array {
    return ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $label, '#button_type' => 'primary']];
  }

}
