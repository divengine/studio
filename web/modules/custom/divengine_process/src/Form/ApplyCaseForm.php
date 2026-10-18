<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\divengine_process\DevelopmentProcess;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-applies the Development Case, replacing the Development's policy.
 */
class ApplyCaseForm extends ConfirmFormBase {

  protected DevelopmentInterface $development;

  public function __construct(protected DevelopmentProcess $process) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_process.development'));
  }

  public function getFormId(): string {
    return 'divengine_process_apply_case';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?DevelopmentInterface $development = NULL): array {
    $this->development = $development;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('Apply the Development Case to %development?', ['%development' => $this->development->label()]);
  }

  public function getDescription() {
    return $this->t("The current policy of this Development is replaced by the case's current tailoring.");
  }

  public function getConfirmText() {
    return $this->t('Apply');
  }

  public function getCancelUrl(): Url {
    return $this->development->toUrl();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->process->applyCase($this->development);
      $this->messenger()->addStatus($this->development->getRevisionLogMessage());
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
