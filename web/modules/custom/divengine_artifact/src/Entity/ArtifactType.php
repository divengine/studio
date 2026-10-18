<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ConfigEntityType(
  id: 'artifact_type',
  label: new TranslatableMarkup('Artifact type'),
  label_collection: new TranslatableMarkup('Artifact types'),
  label_singular: new TranslatableMarkup('artifact type'),
  label_plural: new TranslatableMarkup('artifact types'),
  admin_permission: 'administer artifact types',
  handlers: [
    'list_builder' => 'Drupal\Core\Entity\EntityListBuilder',
    'view_builder' => 'Drupal\Core\Entity\EntityViewBuilder',
    'form' => [
      'default' => 'Drupal\divengine_artifact\Form\ArtifactTypeForm',
      'add' => 'Drupal\divengine_artifact\Form\ArtifactTypeForm',
      'edit' => 'Drupal\divengine_artifact\Form\ArtifactTypeForm',
      'delete' => 'Drupal\divengine_artifact\Form\ArtifactTypeDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  config_prefix: 'artifact_type',
  bundle_of: 'artifact',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'new_revision',
    'key_prefix',
    'uuid',
  ],
  links: [
    'canonical' => '/admin/divengine/artifact-types/{artifact_type}',
    'add-form' => '/admin/divengine/artifact-types/add',
    'edit-form' => '/admin/divengine/artifact-types/{artifact_type}/edit',
    'delete-form' => '/admin/divengine/artifact-types/{artifact_type}/delete',
    'collection' => '/admin/divengine/artifact-types',
  ],
)]
class ArtifactType extends ConfigEntityBundleBase implements RevisionableEntityBundleInterface {

  protected $id;

  protected $label;

  protected $description;

  /**
   * Whether saving an artifact of this type creates a new revision by default.
   *
   * @var bool
   */
  protected $new_revision = TRUE;

  /**
   * Prefix of the keys assigned to new artifacts, e.g. "REQ" for REQ-0001.
   *
   * Empty: keys are typed by hand.
   */
  protected string $key_prefix = '';

  public function getKeyPrefix(): string {
    return $this->key_prefix;
  }

  /**
   * {@inheritdoc}
   */
  public function shouldCreateNewRevision(): bool {
    return (bool) $this->new_revision;
  }

}
