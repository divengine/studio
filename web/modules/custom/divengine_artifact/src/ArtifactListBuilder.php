<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ArtifactListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  public function buildHeader(): array {
    return [
      'artifact_key' => $this->t('Key'),
      'title' => $this->t('Title'),
      'type' => $this->t('Type'),
      'status' => $this->t('Published'),
      'uid' => $this->t('Owner'),
      'changed' => $this->t('Changed'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_artifact\Entity\ArtifactInterface $entity */
    $type_entities = $entity->get('type')->referencedEntities();
    $type_label = $type_entities ? reset($type_entities)->label() : '';

    return [
      'artifact_key' => $entity->getArtifactKey(),
      'title' => Link::createFromRoute(
        $entity->getTitle(),
        'entity.artifact.canonical',
        ['artifact' => $entity->id()]
      ),
      'type' => $type_label,
      'status' => $entity->isPublished() ? $this->t('Yes') : $this->t('No'),
      'uid' => $entity->getOwner() ? $entity->getOwner()->getDisplayName() : '',
      'changed' => $this->dateFormatter->format($entity->getChangedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    if (isset($operations['edit'])) {
      $operations['edit']['query']['destination'] = $entity->toUrl('collection')->toString();
    }
    return $operations;
  }

}
