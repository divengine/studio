<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Session\AccountInterface;

/**
 * The QA roles, cumulative: User < Tester < Lead < Manager.
 *
 * Permissions are granted per role by the recipe; these helpers read them
 * the way the roles are meant, so a Manager is also a Lead and a Tester.
 */
final class QaPermissions {

  public static function isUser(AccountInterface $account): bool {
    return $account->hasPermission('use qa') || static::isTester($account);
  }

  public static function isTester(AccountInterface $account): bool {
    return $account->hasPermission('execute qa tests') || static::isLead($account);
  }

  public static function isLead(AccountInterface $account): bool {
    return $account->hasPermission('manage qa') || static::isManager($account);
  }

  public static function isManager(AccountInterface $account): bool {
    return $account->hasPermission('administer qa');
  }

}
