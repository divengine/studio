<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Whether Studio may ask providers about external work.
 */
class ExternalSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'divengine_external_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['divengine_external.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['observe_github'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Read the title and state of public GitHub work'),
      '#default_value' => $this->config('divengine_external.settings')->get('observe_github'),
      '#description' => $this->t('When people press Refresh on a GitHub reference, Studio asks api.github.com anonymously, at most once a minute per reference. Nothing is asked in the background, private repositories are not readable, and references work the same without it.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('divengine_external.settings')->set('observe_github', (bool) $form_state->getValue('observe_github'))->save();
    parent::submitForm($form, $form_state);
  }

}
