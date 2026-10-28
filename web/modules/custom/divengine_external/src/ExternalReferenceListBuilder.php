<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * External work referenced by the Developments the viewer sees.
 */
class ExternalReferenceListBuilder extends EntityListBuilder {

  protected $limit = 50;

  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC')
      ->sort('id', 'DESC');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  public function buildHeader(): array {
    return [
      'code' => $this->t('Code'),
      'label' => $this->t('External work'),
      'kind' => $this->t('Kind'),
      'development' => $this->t('Development'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_external\Entity\ExternalReference $entity */
    return [
      'code' => (string) $entity->get('code')->value,
      'label' => $entity->toLink(),
      'kind' => \Drupal::service('plugin.manager.divengine_external_provider')->kindLabel((string) $entity->get('provider')->value, (string) $entity->get('kind')->value),
      'development' => $entity->getDevelopment()?->label() ?? '',
    ] + parent::buildRow($entity);
  }

  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No external work referenced yet. Link it from the Traces of a requirement, decision or evidence.');
    return $build;
  }

}
