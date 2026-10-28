<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_oauth\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth 2.0 Dynamic Client Registration (RFC 7591) for MCP clients.
 *
 * MCP clients such as claude.ai register themselves before the authorization
 * code flow. Registration is open, as those clients expect, but a client gets
 * nothing by registering: a person still has to log in and allow it, and the
 * consent page names the host its codes go to. Every client is limited to the
 * MCP scopes and must use PKCE. See ADR-0017.
 */
final class ClientRegistrationController extends ControllerBase {

  const ACCESS_TOKEN_TTL = 3600;

  const REFRESH_TOKEN_TTL = 30 * 86400;

  const MAX_REDIRECT_URIS = 10;

  const FLOOD_EVENT = 'divengine_mcp_oauth.register';

  const FLOOD_LIMIT = 20;

  const FLOOD_WINDOW = 3600;

  /**
   * Schemes that can never carry an authorization code safely.
   */
  const FORBIDDEN_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss'];

  /**
   * Hosts allowed with plain http (RFC 8252 section 7.3).
   */
  const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

  public function __construct(
    protected FloodInterface $flood,
    protected UuidInterface $uuid,
    protected TimeInterface $time,
  ) {}

  /**
   * Registers a client and answers with its client_id.
   */
  public function register(Request $request): JsonResponse {
    if (!$this->flood->isAllowed(static::FLOOD_EVENT, static::FLOOD_LIMIT, static::FLOOD_WINDOW)) {
      return $this->error('too_many_requests', 'Too many client registrations from this address. Try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register(static::FLOOD_EVENT, static::FLOOD_WINDOW);

    $metadata = json_decode($request->getContent(), TRUE);
    if (!is_array($metadata) || array_is_list($metadata)) {
      return $this->error('invalid_client_metadata', 'The request body must be a JSON object.');
    }

    $redirect_uris = $metadata['redirect_uris'] ?? NULL;
    if (!is_array($redirect_uris) || !$redirect_uris || !array_is_list($redirect_uris) || count($redirect_uris) > static::MAX_REDIRECT_URIS) {
      return $this->error('invalid_redirect_uri', sprintf('redirect_uris must list between 1 and %d URIs.', static::MAX_REDIRECT_URIS));
    }
    foreach ($redirect_uris as $uri) {
      if (!is_string($uri) || !static::isAllowedRedirectUri($uri)) {
        return $this->error('invalid_redirect_uri', 'Redirect URIs must be https, http on a loopback address, or a native application scheme, without a fragment.');
      }
    }

    $grant_types = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
    if (!is_array($grant_types) || !in_array('authorization_code', $grant_types, TRUE) || array_diff($grant_types, ['authorization_code', 'refresh_token'])) {
      return $this->error('invalid_client_metadata', 'Only the authorization_code and refresh_token grant types are supported.');
    }
    $response_types = $metadata['response_types'] ?? ['code'];
    if ($response_types !== ['code']) {
      return $this->error('invalid_client_metadata', 'Only the "code" response type is supported.');
    }
    $auth_method = $metadata['token_endpoint_auth_method'] ?? 'none';
    if (!in_array($auth_method, ['none', 'client_secret_post', 'client_secret_basic'], TRUE)) {
      return $this->error('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
    }

    $name = is_string($metadata['client_name'] ?? NULL) ? trim(strip_tags($metadata['client_name'])) : '';
    $name = mb_substr($name !== '' ? $name : 'MCP client', 0, 100);
    $secret = $auth_method === 'none' ? NULL : bin2hex(random_bytes(32));
    $client_id = $this->uuid->generate();

    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    $consumer = $this->entityTypeManager()->getStorage('consumer')->create([
      // The consent page shows this label: the host tells the person where
      // the authorization code will be sent.
      'label' => sprintf('%s (%s)', $name, static::redirectHost($redirect_uris[0])),
      'client_id' => $client_id,
      'description' => 'Registered by an MCP client through dynamic client registration.',
      'grant_types' => array_values(array_unique($grant_types)),
      'redirect' => $redirect_uris,
      'confidential' => $secret !== NULL,
      'secret' => $secret,
      'third_party' => TRUE,
      'pkce' => TRUE,
      'automatic_authorization' => FALSE,
      'remember_approval' => TRUE,
      // Every MCP scope the site has. A token can only do what its person
      // may do, so the write scope is of use only to MCP writers.
      'authorization_code_scopes' => array_map(fn (string $scope) => ['scope_id' => $scope], OAuthMetadataController::scopes()),
      'access_token_expiration' => static::ACCESS_TOKEN_TTL,
      'refresh_token_expiration' => static::REFRESH_TOKEN_TTL,
    ]);
    $violations = $consumer->validate();
    if ($violations->count()) {
      return $this->error('invalid_client_metadata', (string) $violations->get(0)->getMessage());
    }
    $consumer->save();

    $data = [
      'client_id' => $client_id,
      'client_id_issued_at' => $this->time->getRequestTime(),
      'client_name' => $name,
      'redirect_uris' => $redirect_uris,
      'grant_types' => array_values(array_unique($grant_types)),
      'response_types' => ['code'],
      'token_endpoint_auth_method' => $auth_method,
      'scope' => implode(' ', OAuthMetadataController::scopes()),
    ];
    if ($secret !== NULL) {
      $data['client_secret'] = $secret;
      $data['client_secret_expires_at'] = 0;
    }
    return $this->json($data, Response::HTTP_CREATED);
  }

  /**
   * Whether a redirect URI may receive authorization codes.
   */
  public static function isAllowedRedirectUri(string $uri): bool {
    if (strlen($uri) > 255 || str_contains($uri, '#')) {
      return FALSE;
    }
    $parts = parse_url($uri);
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme === '' || !preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) || in_array($scheme, static::FORBIDDEN_SCHEMES, TRUE)) {
      return FALSE;
    }
    $host = strtolower($parts['host'] ?? '');
    return match ($scheme) {
      'https' => $host !== '',
      'http' => in_array($host, static::LOOPBACK_HOSTS, TRUE),
      // Native applications (RFC 8252 section 7.1), for example cursor://.
      default => TRUE,
    };
  }

  /**
   * What the consent page shows of a redirect URI: its host, or its scheme.
   */
  protected static function redirectHost(string $uri): string {
    $parts = parse_url($uri);
    return $parts['host'] ?? ($parts['scheme'] . '://');
  }

  /**
   * An RFC 7591 error response.
   */
  protected function error(string $code, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse {
    return $this->json(['error' => $code, 'error_description' => $description], $status);
  }

  /**
   * A JSON response that is never cached.
   */
  protected function json(array $data, int $status): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Access-Control-Allow-Origin', '*');
    return $response;
  }

}
