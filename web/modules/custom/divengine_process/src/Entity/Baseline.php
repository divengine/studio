<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Access\ScopedAccessControlHandler;
use Drupal\divengine_process\BaselineViewBuilder;
use Drupal\divengine_process\Form\ScopedEntityForm;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A reproducible snapshot of a Development's baselined records.
 *
 * It stores the exact revision IDs of the artifacts and trace links that
 * were baselined when it was taken (see BaselineRecorder); those revisions
 * are never deleted afterwards. Its items never change: only its name and
 * notes can be edited.
 */
#[ContentEntityType(
  id: 'baseline',
  label: new TranslatableMarkup('Baseline'),
  label_collection: new TranslatableMarkup('Baselines'),
  label_singular: new TranslatableMarkup('baseline'),
  label_plural: new TranslatableMarkup('baselines'),
  handlers: [
    'view_builder' => BaselineViewBuilder::class,
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => ScopedAccessControlHandler::class,
    'form' => [
      'default' => ScopedEntityForm::class,
      'edit' => ScopedEntityForm::class,
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider',
    ],
  ],
  base_table: 'baseline',
  admin_permission: 'administer unified process',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  constraints: [
    'ProcessDevelopmentEditable' => [],
    'ProcessSameDevelopment' => [],
  ],
  links: [
    'canonical' => '/baseline/{baseline}',
    'edit-form' => '/baseline/{baseline}/edit',
  ],
)]
class Baseline extends ContentEntityBase implements EntityOwnerInterface {

  use DevelopmentScopedTrait;
  use EntityOwnerTrait;

  public function label() {
    $code = (string) $this->get('code')->value;
    $name = (string) $this->get('name')->value;
    return $code !== '' ? "[$code] $name" : $name;
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew() && (string) $this->get('code')->value === '') {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next('BL'));
    }
  }

  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::database()->delete('divengine_process_baseline_item')
      ->condition('baseline_id', array_keys($entities), 'IN')
      ->execute();
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    // Removing the header would erase its items and release all revision
    // protections. Retention cannot depend on the absence of a delete form.
    throw new \Drupal\Core\Entity\EntityStorageException('Baselines are retained as historical records and cannot be deleted.');
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Taken by'))
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 10, 'settings' => ['link' => FALSE]])
      ->setDisplayConfigurable('view', TRUE);
    $fields['development'] = static::developmentFieldDefinition()
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setSetting('max_length', 32);
    $fields['name'] = ProcessFields::string(new TranslatableMarkup('Name'), -10, TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden']);
    $fields['milestone'] = ProcessFields::reference(new TranslatableMarkup('Milestone'), 'milestone', -5)
      ->setDescription(new TranslatableMarkup('The milestone whose reaching took it, if any.'))
      ->setDisplayOptions('form', ['region' => 'hidden']);
    $fields['notes'] = ProcessFields::text(new TranslatableMarkup('Notes'), 0);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Taken on'))
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'timestamp', 'weight' => 5])
      ->setDisplayConfigurable('view', TRUE);
    return $fields;
  }

}
