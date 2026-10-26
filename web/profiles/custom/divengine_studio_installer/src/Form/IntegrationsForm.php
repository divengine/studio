<?php

declare(strict_types=1);

namespace Drupal\divengine_studio_installer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Installer step: optional integrations installed with Studio.
 */
class IntegrationsForm extends FormBase {

  /**
   * State key that records whether the MCP integration was selected.
   */
  const STATE_MCP = 'divengine_studio_installer.mcp';

  public function getFormId(): string {
    return 'divengine_studio_installer_integrations_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?array &$install_state = NULL): array {
    $form['#title'] = $this->t('Choose integrations');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Every part of Divengine Studio is installed: artifacts, traceability, Unified Process, quality, projects, timesheets, knowledge, diagrams, code and database documentation. Integrations are optional and can be added later.') . '</p>',
    ];
    $form['mcp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('MCP integration'),
      '#description' => $this->t('Lets AI clients read Studio through a read-only MCP server, with OAuth 2.1 or a token. After installation, an operator creates the OAuth signing keys with <code>drush divengine:mcp-oauth-setup</code>, and the service account and its token with <code>drush divengine:mcp-setup</code>.'),
      '#default_value' => FALSE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and continue'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::state()->set(static::STATE_MCP, (bool) $form_state->getValue('mcp'));
  }

}
