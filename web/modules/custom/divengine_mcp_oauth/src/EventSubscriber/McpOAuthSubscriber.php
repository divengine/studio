<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_oauth\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\divengine_mcp_oauth\Controller\OAuthMetadataController;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ties OAuth tokens with the "mcp" scope to the MCP endpoint (ADR-0017).
 *
 * - A 401 from /mcp/post says where the protected resource metadata is
 *   (RFC 9728 section 5.1), which is how MCP clients start the OAuth flow.
 * - simple_oauth authenticates bearer tokens on every route. A token issued
 *   to an MCP client is only accepted on /mcp/post, so a client cannot use it
 *   against JSON:API or any other route with the person's account.
 */
final class McpOAuthSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Refuses MCP tokens outside the MCP endpoint.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $event->getRequest()->attributes->get('_route') === 'mcp.post') {
      return;
    }
    $account = $this->currentUser->getAccount();
    if (!$account instanceof TokenAuthUserInterface) {
      return;
    }
    $scopes = array_column($account->getToken()->get('scopes')->getValue(), 'scope_id');
    if (in_array(OAuthMetadataController::SCOPE, $scopes, TRUE)) {
      throw new AccessDeniedHttpException('This token is only valid for the MCP endpoint.');
    }
  }

  /**
   * Adds the OAuth challenge to 401 responses from the MCP endpoint.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if ($response->getStatusCode() !== Response::HTTP_UNAUTHORIZED || $event->getRequest()->getPathInfo() !== '/mcp/post') {
      return;
    }
    $metadata = Url::fromRoute('divengine_mcp_oauth.protected_resource.mcp')->setAbsolute()->toString();
    $response->headers->set('WWW-Authenticate', sprintf('Bearer resource_metadata="%s", scope="%s"', $metadata, OAuthMetadataController::SCOPE));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing (32) and the authentication provider filter (31).
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::RESPONSE => ['onResponse'],
    ];
  }

}
