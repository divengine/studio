<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_oauth\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Reports on the status page whether OAuth tokens can be signed.
 */
class McpOAuthRequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $settings = $this->configFactory->get('simple_oauth.settings');
    $ready = TRUE;
    foreach (['private_key', 'public_key'] as $key) {
      $file = (string) $settings->get($key);
      $ready = $ready && $file !== '' && is_readable($file);
    }
    return [
      'divengine_mcp_oauth' => [
        'title' => $this->t('MCP OAuth'),
        'value' => $ready ? $this->t('Ready') : $this->t('No token signing keys'),
        'description' => $ready ? '' : $this->t('AI clients cannot connect with OAuth until <code>drush divengine:mcp-oauth-setup</code> runs on the server.'),
        'severity' => $ready ? RequirementSeverity::OK : RequirementSeverity::Warning,
      ],
    ];
  }

}
