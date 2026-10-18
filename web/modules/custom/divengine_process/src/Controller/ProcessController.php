<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_process\DevelopmentProcess;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Lifecycle\LifecycleManager;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\divengine_process\Lifecycle\TransitionException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * One-click process actions: transitions and adding missing phases.
 */
class ProcessController extends ControllerBase {

  public function __construct(
    protected LifecycleManager $lifecycle,
    protected DevelopmentProcess $process,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('divengine_process.lifecycle'),
      $container->get('divengine_process.development'),
    );
  }

  /**
   * Applies a transition and returns to the Development.
   */
  public function transition(EntityInterface $entity, string $transition): RedirectResponse {
    /** @var \Drupal\divengine_process\Lifecycle\StatefulInterface $entity */
    try {
      $this->lifecycle->apply($entity, $transition);
    }
    catch (TransitionException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    return $this->backTo($entity);
  }

  /**
   * Access to a transition: edit access and a transition that applies now.
   */
  public function transitionAccess(EntityInterface $entity, string $transition, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof StatefulInterface || !isset($entity::transitions()[$transition])) {
      return AccessResult::forbidden()->addCacheableDependency($entity);
    }
    $available = isset($this->lifecycle->available($entity)[$transition]);
    return $entity->access('update', $account, TRUE)
      ->andIf(AccessResult::allowedIf($available))
      ->addCacheableDependency($entity);
  }

  /**
   * Creates the missing standard phases and milestones.
   */
  public function ensurePhases(DevelopmentInterface $development): RedirectResponse {
    $created = $this->process->ensureStandardPhases($development);
    $this->messenger()->addStatus($created
      ? $this->formatPlural($created, 'Added 1 phase or milestone.', 'Added @count phases and milestones.')
      : $this->t('Nothing was missing.'));
    return $this->backTo($development);
  }

  protected function backTo(EntityInterface $entity): RedirectResponse {
    $development = $entity->hasField('development') ? $entity->get('development')->entity : NULL;
    return new RedirectResponse(($development ?? $entity)->toUrl()->toString());
  }

}
