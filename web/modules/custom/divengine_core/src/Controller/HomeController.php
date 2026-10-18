<?php

declare(strict_types=1);

namespace Drupal\divengine_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The front page of the Studio workspace.
 *
 * Visitors are sent to log in and come back here; signed-in users land on
 * the first workspace page they may open.
 */
class HomeController extends ControllerBase {

  /**
   * Workspace pages in order of preference.
   */
  const LANDING_ROUTES = [
    'entity.development.collection',
    'entity.artifact.collection',
    'entity.diagram.collection',
  ];

  public function home(): RedirectResponse|array {
    if ($this->currentUser()->isAnonymous()) {
      return $this->redirect('user.login', [], ['query' => ['destination' => Url::fromRoute('<front>')->toString()]]);
    }
    foreach (static::LANDING_ROUTES as $route_name) {
      try {
        $url = Url::fromRoute($route_name);
      }
      catch (\Exception) {
        // The module providing the page is not installed.
        continue;
      }
      if ($url->access()) {
        return new RedirectResponse($url->toString());
      }
    }
    return [
      '#markup' => $this->t('Your account has no access to the Studio workspace yet. Ask an administrator to give you a role.'),
      '#cache' => ['contexts' => ['user.permissions']],
    ];
  }

}
