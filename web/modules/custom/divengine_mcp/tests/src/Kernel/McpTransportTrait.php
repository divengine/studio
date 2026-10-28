<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\divengine_mcp\Drush\Commands\DivengineMcpCommands;
use Drupal\simple_oauth\Entities\UserEntity;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests to /mcp/post through the site, with real credentials.
 *
 * Installs Studio's modules and the MCP recipe, then authenticates the way
 * clients do: the service account token (Authorization: Basic) or an OAuth
 * access token obtained with the authorization code grant (Bearer).
 */
trait McpTransportTrait {

  /**
   * The current service account token.
   */
  protected string $mcpToken = '';

  /**
   * Installs Studio's modules, the MCP recipe and token access for $bot.
   *
   * @param string[] $modules
   *   Studio modules to install before the recipe.
   */
  protected function setUpMcpSite(array $modules): void {
    $this->installEntitySchema('user');
    // The authenticated role and the flood limits the MCP provider reads.
    $this->installConfig(['system', 'user']);
    $this->container->get('module_installer')->install($modules);
    RecipeRunner::processRecipe(Recipe::createFromDirectory(dirname($this->root) . '/recipes/divengine_studio_mcp'));

    // Signing keys for simple_oauth, as "drush divengine:mcp-oauth-setup".
    $dir = $this->siteDirectory . '/oauth';
    mkdir($dir, 0700, TRUE);
    $this->container->get('simple_oauth.key.generator')->generateKeys($dir);
    $this->config('simple_oauth.settings')
      ->set('private_key', "$dir/private.key")
      ->set('public_key', "$dir/public.key")
      ->save();
  }

  /**
   * Gives token access to an account, as "drush divengine:mcp-setup".
   */
  protected function grantMcpToken(AccountInterface $account): string {
    $this->mcpToken = bin2hex(random_bytes(32));
    $storage = $this->container->get('entity_type.manager')->getStorage('key');
    $key = $storage->load(DivengineMcpCommands::KEY) ?: $storage->create([
      'id' => DivengineMcpCommands::KEY,
      'label' => 'Divengine MCP token',
      'key_type' => 'authentication',
      'key_input' => 'none',
      'key_provider' => 'config',
    ]);
    $key->set('key_provider_settings', ['key_value' => $this->mcpToken]);
    $key->save();
    $this->config('mcp.settings')
      ->set('auth_settings', [
        'enable_token_auth' => TRUE,
        'token_key' => DivengineMcpCommands::KEY,
        'token_user' => (int) $account->id(),
        'enable_basic_auth' => FALSE,
      ])
      ->save();
    return $this->mcpToken;
  }

  /**
   * An access token of $account for $scopes, through the authorization code
   * grant, as a client gets it after the person pressed Allow.
   */
  protected function oauthToken(AccountInterface $account, array $scopes = ['mcp']): string {
    $secret = bin2hex(random_bytes(16));
    $redirect = 'https://client.example.com/callback';
    $consumer = Consumer::create([
      'label' => 'Test client',
      'client_id' => 'test-' . bin2hex(random_bytes(6)),
      'secret' => $secret,
      'confidential' => TRUE,
      'third_party' => TRUE,
      'pkce' => FALSE,
      // The consent step itself is simple_oauth's; it is not under test.
      'automatic_authorization' => TRUE,
      'grant_types' => ['authorization_code'],
      'redirect' => [$redirect],
      'authorization_code_scopes' => array_map(fn ($scope) => ['scope_id' => $scope], $scopes),
    ]);
    $consumer->save();

    // What /oauth/authorize does once the person is logged in and presses
    // Allow (Oauth2AuthorizeController); the login session is not under test.
    $server = $this->container->get('simple_oauth.server.authorization_server.factory')->get($consumer);
    $authorization = $server->validateAuthorizationRequest($this->container->get('psr7.http_message_factory')->createRequest(Request::create('/oauth/authorize', 'GET', [
      'response_type' => 'code',
      'client_id' => $consumer->getClientId(),
      'scope' => implode(' ', $scopes),
      'redirect_uri' => $redirect,
    ])));
    $user = new UserEntity();
    $user->setIdentifier($account->id());
    $authorization->setUser($user);
    $authorization->setAuthorizationApproved(TRUE);
    $location = $server->completeAuthorizationRequest($authorization, new Psr7Response())->getHeaderLine('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $this->assertNotEmpty($query['code'] ?? NULL, 'The authorization returns a code.');

    $response = $this->handle(Request::create('/oauth/token', 'POST', [
      'grant_type' => 'authorization_code',
      'client_id' => $consumer->getClientId(),
      'client_secret' => $secret,
      'code' => $query['code'],
      'redirect_uri' => $redirect,
    ]));
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertNotEmpty($data['access_token'] ?? NULL, 'The token endpoint returns an access token: ' . $response->getStatusCode());
    return $data['access_token'];
  }

  /**
   * Sends one JSON-RPC request to /mcp/post.
   *
   * @param string|null $authorization
   *   The Authorization header, or NULL for none.
   */
  protected function mcpRequest(string $method, array $params, ?string $authorization): Response {
    // Like a real request, the header is in the server variables, where
    // tools that issue sub-requests (JSON:API) find it.
    $server = $authorization === NULL ? [] : ['HTTP_AUTHORIZATION' => $authorization];
    $request = Request::create('http://studio.example.com/mcp/post', 'POST', [], [], [], $server, json_encode([
      'jsonrpc' => '2.0',
      'id' => 1,
      'method' => $method,
      'params' => (object) $params,
    ]));
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json, text/event-stream');
    $response = $this->handle($request);
    // Every request authenticates on its own, as separate HTTP requests do.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    return $response;
  }

  /**
   * Handles a request as a new HTTP request.
   */
  protected function handle(Request $request): Response {
    $this->startRequest();
    // An error page rendered for an earlier request leaves its method and
    // path in the router context.
    $this->container->get('router.request_context')->fromRequest($request);
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Forgets what earlier requests loaded, as a new HTTP request starts.
   */
  protected function startRequest(): void {
    $this->container->get('entity.memory_cache')->deleteAll();
    // Access handlers remember their answers for the rest of a request.
    foreach (array_keys($this->container->get('entity_type.manager')->getDefinitions()) as $entity_type_id) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($entity_type_id)->resetCache();
    }
    drupal_static_reset();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    // A request that ended in an exception stays on the stack; keep only the
    // test's own request.
    $stack = $this->container->get('request_stack');
    while ($stack->getParentRequest()) {
      $stack->pop();
    }
  }

  /**
   * The Authorization header for the service account token.
   */
  protected function basic(?string $token = NULL): string {
    return 'Basic ' . base64_encode($token ?? $this->mcpToken);
  }

  /**
   * Calls a tool and returns the text of its answer.
   */
  protected function callTool(string $name, array $arguments, string $authorization): string {
    $response = $this->mcpRequest('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $authorization);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $response->getContent(), TRUE);
    if (isset($data['error'])) {
      return 'JSON-RPC error: ' . ($data['error']['message'] ?? '');
    }
    return implode("\n", array_column($data['result']['content'] ?? [], 'text'));
  }

  /**
   * The names of the tools an authorization lists.
   *
   * @return string[]
   */
  protected function toolNames(string $authorization): array {
    $response = $this->mcpRequest('tools/list', [], $authorization);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return array_column(json_decode((string) $response->getContent(), TRUE)['result']['tools'] ?? [], 'name');
  }

}
