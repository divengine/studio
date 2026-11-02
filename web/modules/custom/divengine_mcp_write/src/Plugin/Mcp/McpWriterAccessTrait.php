<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write\Plugin\Mcp;

use Drupal\Core\Access\AccessResult;
use Drupal\divengine_mcp_oauth\Controller\OAuthMetadataController;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;

/**
 * Who may write through MCP: a person's OAuth token with the write scope, of
 * an MCP writer (ADR-0019, #79).
 *
 * Permissions are not enough on their own: user 1 and administrator roles
 * have every permission, whatever the scope, and a token's roles are those of
 * its scopes. So the scope is read from the token, and the MCP writer role
 * from the person's account as stored now. The answer is never cached:
 * removing the role or the token takes effect on the next request.
 *
 * Needs $this->currentUser (McpPluginBase) and $this->entityTypeManager.
 */
trait McpWriterAccessTrait {

  /**
   * The role a person must hold, explicitly, for their clients to write.
   */
  public const WRITER_ROLE = 'mcp_writer';

  protected function writerAccess(): AccessResult {
    $account = $this->currentUser->getAccount();
    if (!$account instanceof TokenAuthUserInterface) {
      return AccessResult::forbidden('Writes need an OAuth token.')->setCacheMaxAge(0);
    }
    $scopes = array_column($account->getToken()->get('scopes')->getValue(), 'scope_id');
    if (!in_array(OAuthMetadataController::WRITE_SCOPE, $scopes, TRUE)) {
      return AccessResult::forbidden('The token does not have the mcp_write scope.')->setCacheMaxAge(0);
    }
    /** @var \Drupal\user\UserInterface|null $person */
    $person = $this->entityTypeManager->getStorage('user')->loadUnchanged($account->id());
    if (!$person || !$person->isActive() || !in_array(static::WRITER_ROLE, $person->getRoles(TRUE), TRUE)) {
      return AccessResult::forbidden('The person does not have the MCP writer role.')->setCacheMaxAge(0);
    }
    return AccessResult::allowedIfHasPermission($account, 'write engineering records through mcp')->setCacheMaxAge(0);
  }

}
