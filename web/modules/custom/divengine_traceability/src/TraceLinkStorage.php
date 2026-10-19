<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/** Trace edits append revisions; callers cannot opt into in-place writes. */
class TraceLinkStorage extends SqlContentEntityStorage {

  protected function doSave($id, EntityInterface $entity) {
    if (!$entity->isNew()) {
      // Enforce again after presave hooks, immediately before revision storage.
      if (!$entity->isNewRevision()) {
        $entity->setRevisionUserId((int) \Drupal::currentUser()->id());
        $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      }
      $entity->setNewRevision(TRUE);
    }
    return parent::doSave($id, $entity);
  }

}
