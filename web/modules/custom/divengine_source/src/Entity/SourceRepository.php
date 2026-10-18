<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\DevelopmentScopedTrait;
use Drupal\divengine_source\Access\SourceAccessControlHandler;
use Drupal\divengine_source\Form\SourceEntityForm;
use Drupal\divengine_source\SourceViewBuilder;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A local Git clone that holds code of a Development.
 *
 * Its path must lie inside the allowed roots; once something was indexed it
 * cannot change, because the history refers to that clone's objects. The
 * code itself is never copied: it is read from Git when shown.
 */
#[ContentEntityType(
  id: 'source_repository',
  label: new TranslatableMarkup('Repository'),
  label_collection: new TranslatableMarkup('Repositories'),
  label_singular: new TranslatableMarkup('repository'),
  label_plural: new TranslatableMarkup('repositories'),
  handlers: [
    'view_builder' => SourceViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => SourceAccessControlHandler::class,
    'form' => [
      'default' => SourceEntityForm::class,
      'add' => SourceEntityForm::class,
      'edit' => SourceEntityForm::class,
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'source_repository',
  admin_permission: 'administer source code',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'SourceRepositoryPath' => [],
  ],
  links: [
    'canonical' => '/source/{source_repository}',
    'add-form' => '/source/add',
    'edit-form' => '/source/{source_repository}/edit',
    'delete-form' => '/source/{source_repository}/delete',
  ],
)]
class SourceRepository extends ContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use DevelopmentScopedTrait;
  use EntityChangedTrait;
  use EntityOwnerTrait;

  public function label() {
    return '[' . $this->get('code')->value . '] ' . $this->get('name')->value;
  }

  /**
   * The default ref, else the first one.
   */
  public function getDefaultRef(): ?SourceRef {
    $refs = \Drupal::entityTypeManager()->getStorage('source_ref')->loadByProperties(['repository' => $this->id(), 'status' => 1]);
    uasort($refs, fn ($a, $b) => [!$a->get('is_default')->value, $a->get('name')->value] <=> [!$b->get('is_default')->value, $b->get('name')->value]);
    return reset($refs) ?: NULL;
  }

  /**
   * Whether anything of this repository was indexed.
   */
  public function hasHistory(): bool {
    return !$this->isNew() && (bool) \Drupal::entityTypeManager()->getStorage('source_snapshot')->getQuery()->accessCheck(FALSE)->condition('repository', $this->id())->range(0, 1)->execute();
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew() && trim((string) $this->get('code')->value) === '') {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next('SRC'));
    }
    $this->set('path', rtrim(trim((string) $this->get('path')->value), '/'));
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service('divengine_source.index')->forgetRepositories(array_keys($entities));
    $refs = \Drupal::entityTypeManager()->getStorage('source_ref');
    $ids = $refs->getQuery()->accessCheck(FALSE)->condition('repository', array_keys($entities), 'IN')->execute();
    if ($ids) {
      $refs->delete($refs->loadMultiple($ids));
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Registered by'));
    $fields['development'] = static::developmentFieldDefinition()
      ->setDescription(new TranslatableMarkup('The Development whose code this is. Only its members see the repository.'));
    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setSetting('max_length', 32)
      ->addConstraint('UniqueField');
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE);
    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Path of the local clone'))
      ->setDescription(new TranslatableMarkup('Absolute path on the server, inside the allowed repository roots, e.g. /opt/repos/billing. Studio never clones, fetches or checks out: keep the clone up to date yourself.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 1024)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE);
    $fields['url'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Remote URL'))
      ->setDescription(new TranslatableMarkup('Where the repository lives (GitHub, GitLab…), for people. Never used by Studio.'))
      ->setDisplayOptions('form', ['type' => 'uri', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE);
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => -7, 'settings' => ['rows' => 3]])
      ->setDisplayConfigurable('form', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Registered on'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
