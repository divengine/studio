<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Sets up token access to the MCP server.
 *
 * Secrets cannot travel in a recipe, so the service account and its token are
 * created here, per installation. The token lives in a file outside the
 * document root and the Key module reads it from there; it is never stored in
 * configuration or printed.
 */
final class DivengineMcpCommands extends DrushCommands {

  use AutowireTrait;

  const ROLE = 'mcp_agent';

  const KEY = 'divengine_mcp_token';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Creates the MCP service account and token and turns on token auth.
   */
  #[CLI\Command(name: 'divengine:mcp-setup')]
  #[CLI\Option(name: 'user', description: 'Name of the service account.')]
  #[CLI\Option(name: 'token-file', description: 'Where to keep the token. Defaults to private/divengine-mcp.token next to the document root.')]
  #[CLI\Option(name: 'regenerate', description: 'Replace an existing token.')]
  #[CLI\Usage(name: 'drush divengine:mcp-setup --uri=https://studio.example.com', description: 'Set up access and print how to connect Claude Code.')]
  public function setup(array $options = ['user' => 'mcp_bot', 'token-file' => NULL, 'regenerate' => FALSE]): int {
    if (!$this->entityTypeManager->getStorage('user_role')->load(static::ROLE)) {
      $this->logger()->error(dt('Role @role does not exist. Apply the divengine_studio_mcp recipe first.', ['@role' => static::ROLE]));
      return self::EXIT_FAILURE;
    }

    $account = $this->ensureAccount($options['user']);
    if (\Drupal::moduleHandler()->moduleExists('divengine_codedocs')) {
      $role = $this->entityTypeManager->getStorage('user_role')->load(static::ROLE);
      $role->grantPermission('use code docs')->save();
      $settings = $this->configFactory->getEditable('mcp.settings');
      if ($settings->get('plugins.codedocs') === NULL) {
        $settings->set('plugins.codedocs', ['enabled' => TRUE, 'config' => []])->save();
      }
    }
    $token_file = $options['token-file'] ?: dirname(DRUPAL_ROOT) . '/private/divengine-mcp.token';
    $this->ensureToken($token_file, (bool) $options['regenerate']);
    $this->ensureKey($token_file);

    $this->configFactory->getEditable('mcp.settings')
      ->set('enable_auth', TRUE)
      ->set('auth_settings', [
        'enable_token_auth' => TRUE,
        'token_key' => static::KEY,
        'token_user' => (int) $account->id(),
        'enable_basic_auth' => FALSE,
      ])
      ->save();

    $endpoint = Url::fromRoute('mcp.post')->setAbsolute()->toString();
    $this->logger()->success(dt('MCP token access is on for @user.', ['@user' => $account->getAccountName()]));
    $this->io()->writeln([
      '',
      dt('Endpoint: @url', ['@url' => $endpoint]),
      dt('Token file: @file (readable by the web server user)', ['@file' => $token_file]),
      '',
      dt('Connect Claude Code:'),
      sprintf('  claude mcp add --transport http divengine-studio %s --header "Authorization: Basic $(base64 -w0 < %s)"', $endpoint, $token_file),
    ]);
    return self::EXIT_SUCCESS;
  }

  /**
   * Loads or creates the service account and gives it the MCP role.
   */
  protected function ensureAccount(string $name): UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    $accounts = $storage->loadByProperties(['name' => $name]);
    /** @var \Drupal\user\UserInterface $account */
    $account = reset($accounts) ?: $storage->create([
      'name' => $name,
      'mail' => $name . '@localhost',
      // Nobody logs in as this account; the password only has to be unknown.
      'pass' => bin2hex(random_bytes(24)),
      'status' => 1,
    ]);
    $account->addRole(static::ROLE);
    $account->activate();
    $account->save();
    return $account;
  }

  /**
   * Writes a new random token unless one is already there.
   */
  protected function ensureToken(string $file, bool $regenerate): void {
    if (is_file($file) && !$regenerate) {
      return;
    }
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, TRUE)) {
      throw new \RuntimeException(dt('Cannot create @dir.', ['@dir' => $dir]));
    }
    $old = umask(0077);
    try {
      if (file_put_contents($file, bin2hex(random_bytes(32))) === FALSE) {
        throw new \RuntimeException(dt('Cannot write @file.', ['@file' => $file]));
      }
    }
    finally {
      umask($old);
    }
  }

  /**
   * Points the Key entity at the token file.
   */
  protected function ensureKey(string $file): void {
    $storage = $this->entityTypeManager->getStorage('key');
    $key = $storage->load(static::KEY) ?: $storage->create([
      'id' => static::KEY,
      'label' => 'Divengine MCP token',
      'key_type' => 'authentication',
      'key_input' => 'none',
    ]);
    $key->set('key_provider', 'file');
    $key->set('key_provider_settings', [
      'file_location' => $file,
      'base64_encoded' => FALSE,
      'strip_line_breaks' => TRUE,
    ]);
    $key->save();
  }

}
