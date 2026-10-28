<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp_oauth\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\divengine_mcp_oauth\Controller\ClientRegistrationController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Discovery and client registration for MCP clients (ADR-0017).
 */
#[RunTestsInSeparateProcesses]
class McpOAuthTest extends KernelTestBase {

  protected static $modules = ['system', 'user'];

  /**
   * The mcp module has no schema for its plugins' settings.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(dirname($this->root) . '/recipes/divengine_studio_mcp'));
  }

  /**
   * Sends a request through the site.
   */
  protected function request(string $method, string $path, ?array $json = NULL): Response {
    $request = Request::create('http://studio.example.com' . $path, $method, [], [], [], [], $json === NULL ? NULL : json_encode($json));
    $request->headers->set('Content-Type', 'application/json');
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Registers a client and returns the response.
   */
  protected function registerClient(array $metadata): Response {
    return $this->request('POST', '/oauth/register', $metadata);
  }

  public function testRecipeSetsUpScopeAndRoles(): void {
    /** @var \Drupal\simple_oauth\Entity\Oauth2ScopeEntityInterface $scope */
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->load('mcp');
    $this->assertNotNull($scope, 'The recipe imports the "mcp" scope.');
    $this->assertSame('role', $scope->getGranularity()->getPluginId());
    $this->assertSame('mcp_agent', $scope->getGranularity()->getConfiguration()['role']);
    $this->assertTrue($scope->isGrantTypeEnabled('authorization_code'));
    $this->assertTrue($scope->isGrantTypeEnabled('refresh_token'));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('oauth2_token_type')->load('access_token'), 'The recipe imports the token types.');

    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->load('mcp_user');
    $this->assertTrue($role->hasPermission('use mcp server'));
    $this->assertTrue($role->hasPermission('grant simple_oauth codes'));
  }

  public function testUnauthenticatedMcpRequestPointsToMetadata(): void {
    $response = $this->request('POST', '/mcp/post', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame(
      'Bearer resource_metadata="http://studio.example.com/.well-known/oauth-protected-resource/mcp/post", scope="mcp mcp_write"',
      $response->headers->get('WWW-Authenticate'),
    );
  }

  public function testDiscoveryDocuments(): void {
    foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp/post'] as $path) {
      $resource = json_decode((string) $this->request('GET', $path)->getContent(), TRUE);
      $this->assertSame('http://studio.example.com/mcp/post', $resource['resource'], $path);
      $this->assertSame(['http://studio.example.com'], $resource['authorization_servers'], $path);
      // The recipe installs engineering writes (ADR-0019); the write scope
      // is of use only to people with the MCP writer role.
      $this->assertSame(['mcp', 'mcp_write'], $resource['scopes_supported'], $path);
    }

    $server = json_decode((string) $this->request('GET', '/.well-known/oauth-authorization-server')->getContent(), TRUE);
    $this->assertSame('http://studio.example.com', $server['issuer']);
    $this->assertSame('http://studio.example.com/oauth/authorize', $server['authorization_endpoint']);
    $this->assertSame('http://studio.example.com/oauth/token', $server['token_endpoint']);
    $this->assertSame('http://studio.example.com/oauth/register', $server['registration_endpoint']);
    $this->assertSame(['mcp', 'mcp_write'], $server['scopes_supported']);
    $this->assertSame(['S256'], $server['code_challenge_methods_supported']);
    $this->assertSame(['authorization_code', 'refresh_token'], $server['grant_types_supported']);
  }

  public function testPublicClientRegistration(): void {
    $response = $this->registerClient([
      'client_name' => 'Claude',
      'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'token_endpoint_auth_method' => 'none',
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayNotHasKey('client_secret', $data);
    $this->assertSame('mcp mcp_write', $data['scope']);

    $consumers = $this->container->get('entity_type.manager')->getStorage('consumer')->loadByProperties(['client_id' => $data['client_id']]);
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    $consumer = reset($consumers);
    $this->assertSame('Claude (claude.ai)', $consumer->label(), 'The consent page shows where codes go.');
    $this->assertFalse((bool) $consumer->get('confidential')->value);
    $this->assertTrue((bool) $consumer->get('pkce')->value);
    $this->assertFalse((bool) $consumer->get('automatic_authorization')->value);
    $this->assertSame(['mcp', 'mcp_write'], array_column($consumer->get('authorization_code_scopes')->getValue(), 'scope_id'));
    $this->assertSame(['https://claude.ai/api/mcp/auth_callback'], array_column($consumer->get('redirect')->getValue(), 'value'));
  }

  public function testConfidentialClientRegistration(): void {
    $response = $this->registerClient([
      'redirect_uris' => ['http://127.0.0.1:33418/callback'],
      'token_endpoint_auth_method' => 'client_secret_post',
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertNotEmpty($data['client_secret']);
    $this->assertSame(0, $data['client_secret_expires_at']);

    $consumers = $this->container->get('entity_type.manager')->getStorage('consumer')->loadByProperties(['client_id' => $data['client_id']]);
    $consumer = reset($consumers);
    $this->assertTrue((bool) $consumer->get('confidential')->value);
    $this->assertNotSame($data['client_secret'], $consumer->get('secret')->value, 'The secret is stored hashed.');
  }

  public static function invalidRegistrations(): array {
    return [
      'no redirect' => [['client_name' => 'x'], 'invalid_redirect_uri'],
      'http elsewhere' => [['redirect_uris' => ['http://evil.example/cb']], 'invalid_redirect_uri'],
      'javascript' => [['redirect_uris' => ['javascript:alert(1)']], 'invalid_redirect_uri'],
      'fragment' => [['redirect_uris' => ['https://example.com/cb#x']], 'invalid_redirect_uri'],
      'implicit' => [['redirect_uris' => ['https://example.com/cb'], 'response_types' => ['token']], 'invalid_client_metadata'],
      'client credentials' => [['redirect_uris' => ['https://example.com/cb'], 'grant_types' => ['client_credentials']], 'invalid_client_metadata'],
      'list body' => [['https://example.com/cb'], 'invalid_client_metadata'],
    ];
  }

  #[DataProvider('invalidRegistrations')]
  public function testInvalidRegistrationIsRefused(array $metadata, string $error): void {
    $response = $this->registerClient($metadata);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame($error, json_decode((string) $response->getContent(), TRUE)['error']);
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('consumer')->loadByProperties(['third_party' => TRUE, 'description' => 'Registered by an MCP client through dynamic client registration.']));
  }

  public static function redirectUris(): array {
    return [
      ['https://claude.ai/api/mcp/auth_callback', TRUE],
      ['http://localhost:33418/callback', TRUE],
      ['http://127.0.0.1:6274/oauth/callback', TRUE],
      ['http://[::1]:8000/cb', TRUE],
      ['cursor://anysphere.cursor-retrieval/oauth/user-divengine/callback', TRUE],
      ['http://studio.example.com/cb', FALSE],
      ['https:///nohost', FALSE],
      ['data:text/html,x', FALSE],
      ['file:///etc/passwd', FALSE],
      ['no-scheme', FALSE],
    ];
  }

  #[DataProvider('redirectUris')]
  public function testRedirectUriRules(string $uri, bool $allowed): void {
    $this->assertSame($allowed, ClientRegistrationController::isAllowedRedirectUri($uri));
  }

}
