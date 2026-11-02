<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_process\DevelopmentReferences;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access to Developments.
 *
 * Whoever sees a Development participates in it: "contribute" lets them add
 * records (#84). Editing the Development itself (details, members,
 * Responsible) and its process structure is "update", for its creator, its
 * Responsible and Process Managers. Creating a Development needs "create
 * developments"; changing who created it, Process Manager. Only Process
 * Managers delete Developments or their revisions, and only once no
 * engineering record belongs to it any more (see DevelopmentReferences;
 * storage enforces the same rule in Development::preDelete()).
 */
class DevelopmentAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  protected DevelopmentVisibility $visibility;

  protected DevelopmentReferences $references;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->visibility = $container->get('divengine_process.visibility');
    $instance->references = $container->get('divengine_process.development_references');
    return $instance;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\divengine_process\Entity\DevelopmentInterface $entity */
    $manager = AccessResult::allowedIfHasPermission($account, 'administer unified process');
    return match ($operation) {
      'view', 'view label', 'view revision', 'view all revisions', 'contribute' => $this->visibility->access($entity, $account),
      'update' => $this->visibility->manages($entity, $account),
      'delete' => ($this->references->dependents($entity->id())
        ? AccessResult::forbidden('The Development still has records.')
        : $manager)->addCacheTags($this->references->cacheTags()),
      default => $manager,
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer unified process')
      ->orIf(AccessResult::allowedIfHasPermissions($account, ['use unified process', 'create developments']));
  }

  /**
   * Who created a Development is changed only by Process Managers.
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    if ($operation === 'edit' && $field_definition->getName() === 'uid') {
      return AccessResult::allowedIfHasPermission($account, 'administer unified process');
    }
    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
