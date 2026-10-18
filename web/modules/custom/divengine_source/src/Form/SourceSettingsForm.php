<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_source\Git\Roots;
use Drupal\divengine_source\SourceGit;

/**
 * Where repositories may live and how much the viewer reads.
 */
class SourceSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'divengine_source_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['divengine_source.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('divengine_source.settings');
    $form['allowed_roots'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed repository roots'),
      '#default_value' => $config->get('allowed_roots'),
      '#description' => $this->t('One absolute path per line. Repositories can only be registered inside these folders (at any depth, symbolic links resolved). Empty means no repository is allowed.'),
      '#rows' => 4,
    ];
    $form['viewer_max_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Largest file shown, in bytes'),
      '#default_value' => $config->get('viewer_max_bytes'),
      '#min' => SourceGit::MIN_VIEWER_BYTES,
      '#max' => SourceGit::MAX_VIEWER_BYTES,
      '#description' => $this->t('Larger files are listed but not read (default 512 KiB, at most 10 MiB).'),
    ];
    $form['git_binary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Git executable'),
      '#default_value' => $config->get('git_binary'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $text = (string) $form_state->getValue('allowed_roots');
    foreach (preg_split('/\R/', $text) as $line) {
      $line = trim($line);
      if ($line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, '/')) {
        $form_state->setErrorByName('allowed_roots', $this->t('%line is not an absolute path.', ['%line' => $line]));
      }
    }
    if (!preg_match('#^[A-Za-z0-9_./-]+$#', (string) $form_state->getValue('git_binary'))) {
      $form_state->setErrorByName('git_binary', $this->t('Enter the name or the absolute path of the git executable.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('divengine_source.settings')
      ->set('allowed_roots', implode("\n", Roots::parse((string) $form_state->getValue('allowed_roots'))))
      ->set('viewer_max_bytes', (int) $form_state->getValue('viewer_max_bytes'))
      ->set('git_binary', trim((string) $form_state->getValue('git_binary')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
