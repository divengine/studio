<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TraceLinkListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  public function buildHeader(): array {
    return [
      'source' => $this->t('Source'),
      'link_type' => $this->t('Link type'),
      'target' => $this->t('Target'),
      'confidence' => $this->t('Confidence'),
      'status' => $this->t('Published'),
      'changed' => $this->t('Changed'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkInterface $entity */
    return [
      'source' => $this->resolveEntityLabel(
        $entity->getSourceEntityType(),
        $entity->getSourceEntityId()
      ),
      'link_type' => $entity->get('link_type')->value ?? '',
      'target' => $this->resolveEntityLabel(
        $entity->getTargetEntityType(),
        $entity->getTargetEntityId()
      ),
      'confidence' => $entity->get('confidence')->value ?? '',
      'status' => $entity->isPublished() ? $this->t('Yes') : $this->t('No'),
      'changed' => $this->dateFormatter->format($entity->getChangedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

  protected function resolveEntityLabel(string $entity_type_id, int $entity_id): string|Link {
    if (empty($entity_type_id) || $entity_id <= 0) {
      return '';
    }
    if ($entity_type_id === 'artifact' && $this->entityTypeManager->hasDefinition('artifact')) {
      $artifact = $this->entityTypeManager->getStorage('artifact')->load($entity_id);
      if ($artifact) {
        return Link::createFromRoute(
          $artifact->getArtifactKey() ?: $artifact->label(),
          'entity.artifact.canonical',
          ['artifact' => $entity_id]
        );
      }
    }
    return $entity_type_id . ':' . $entity_id;
  }

  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    if (isset($operations['edit'])) {
      $operations['edit']['query']['destination'] = $entity->toUrl('collection')->toString();
    }
    return $operations;
  }

}
