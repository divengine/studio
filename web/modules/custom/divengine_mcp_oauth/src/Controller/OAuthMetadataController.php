<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_oauth\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Discovery documents that let MCP clients find out how to get a token.
 *
 * An MCP client that gets a 401 from /mcp/post reads the protected resource
 * metadata, follows it to this site's authorization server metadata, registers
 * itself and runs the authorization code flow with PKCE that simple_oauth
 * provides. See ADR-0017.
 */
final class OAuthMetadataController extends ControllerBase {

  /**
   * The only scope MCP clients are given.
   */
  const SCOPE = 'mcp';

  /**
   * OAuth 2.0 Protected Resource Metadata (RFC 9728) for /mcp/post.
   */
  public function protectedResource(Request $request): CacheableJsonResponse {
    return $this->respond([
      'resource' => Url::fromRoute('mcp.post')->setAbsolute()->toString(),
      'authorization_servers' => [static::issuer($request)],
      'scopes_supported' => [static::SCOPE],
      'bearer_methods_supported' => ['header'],
      'resource_name' => (string) $this->config('system.site')->get('name'),
    ]);
  }

  /**
   * OAuth 2.0 Authorization Server Metadata (RFC 8414).
   */
  public function authorizationServer(Request $request): CacheableJsonResponse {
    return $this->respond([
      'issuer' => static::issuer($request),
      'authorization_endpoint' => Url::fromRoute('oauth2_token.authorize')->setAbsolute()->toString(),
      'token_endpoint' => Url::fromRoute('oauth2_token.token')->setAbsolute()->toString(),
      'registration_endpoint' => Url::fromRoute('divengine_mcp_oauth.register')->setAbsolute()->toString(),
      'jwks_uri' => Url::fromRoute('simple_oauth.jwks')->setAbsolute()->toString(),
      'scopes_supported' => [static::SCOPE],
      'response_types_supported' => ['code'],
      'grant_types_supported' => ['authorization_code', 'refresh_token'],
      'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
      'code_challenge_methods_supported' => ['S256'],
    ]);
  }

  /**
   * The issuer: the site's base URL, without a trailing slash.
   */
  public static function issuer(Request $request): string {
    return $request->getSchemeAndHttpHost() . $request->getBasePath();
  }

  /**
   * A JSON response that varies by site URL and site name.
   */
  protected function respond(array $data): CacheableJsonResponse {
    $response = new CacheableJsonResponse($data);
    // Browser-based clients (for example the MCP Inspector) read these too.
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->addCacheableDependency((new CacheableMetadata())
      ->addCacheContexts(['url.site'])
      ->addCacheableDependency($this->config('system.site')));
    return $response;
  }

}
