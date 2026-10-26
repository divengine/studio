<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_oauth\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth\Service\KeyGeneratorServiceInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sets up OAuth access to the MCP server.
 *
 * The keys that sign access tokens are secrets, so like the MCP token they are
 * created per installation, in files outside the document root.
 */
final class McpOAuthCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'simple_oauth.key.generator')]
    protected KeyGeneratorServiceInterface $keyGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Creates the token signing keys and prints how to connect.
   */
  #[CLI\Command(name: 'divengine:mcp-oauth-setup')]
  #[CLI\Option(name: 'key-dir', description: 'Where to keep the keys. Defaults to private/oauth next to the document root.')]
  #[CLI\Option(name: 'regenerate', description: 'Replace existing keys. Every issued token stops working.')]
  #[CLI\Usage(name: 'drush divengine:mcp-oauth-setup --uri=https://studio.example.com', description: 'Set up OAuth and print how to connect AI clients.')]
  public function setup(array $options = ['key-dir' => NULL, 'regenerate' => FALSE]): int {
    $dir = $options['key-dir'] ?: dirname(DRUPAL_ROOT) . '/private/oauth';
    $private = "$dir/private.key";
    $public = "$dir/public.key";
    if (!is_file($private) || !is_file($public) || $options['regenerate']) {
      if (!is_dir($dir) && !mkdir($dir, 0700, TRUE)) {
        $this->logger()->error(dt('Cannot create @dir.', ['@dir' => $dir]));
        return self::EXIT_FAILURE;
      }
      $this->keyGenerator->generateKeys($dir);
      $this->logger()->success(dt('Token signing keys written to @dir.', ['@dir' => $dir]));
    }
    $this->configFactory->getEditable('simple_oauth.settings')
      ->set('private_key', $private)
      ->set('public_key', $public)
      ->save();

    $endpoint = Url::fromRoute('mcp.post')->setAbsolute()->toString();
    $this->io()->writeln([
      '',
      dt('MCP OAuth is on. Keys: @dir (readable by the web server user only)', ['@dir' => $dir]),
      dt('People need the "MCP user" role to connect an AI client.'),
      '',
      dt('Endpoint: @url', ['@url' => $endpoint]),
      dt('claude.ai / Claude Desktop: Settings > Connectors > Add custom connector, URL above.'),
      dt('Claude Code:'),
      sprintf('  claude mcp add --transport http divengine-studio %s', $endpoint),
    ]);
    return self::EXIT_SUCCESS;
  }

}
