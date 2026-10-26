<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_external\Access\ExternalReferenceAccessControlHandler;
use Drupal\divengine_external\ExternalIdentity;
use Drupal\divengine_external\ExternalReferenceListBuilder;
use Drupal\divengine_external\ExternalReferenceViewBuilder;
use Drupal\divengine_external\Form\ExternalReferenceForm;
use Drupal\divengine_process\Entity\DevelopmentScopedTrait;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Work tracked outside Studio, referenced by a Development.
 *
 * Its identity (provider, host, namespace, kind and remote ID) is read from
 * the link when it is registered and never changes; within a Development
 * there is one reference per external work. Trace links connect it to
 * requirements, decisions and evidence. Studio keeps the link, a label and
 * its own notes, never a copy of the external work. Every change is a new
 * revision.
 */
#[ContentEntityType(
  id: 'external_reference',
  label: new TranslatableMarkup('External reference'),
  label_collection: new TranslatableMarkup('External references'),
  label_singular: new TranslatableMarkup('external reference'),
  label_plural: new TranslatableMarkup('external references'),
  handlers: [
    'view_builder' => ExternalReferenceViewBuilder::class,
    'list_builder' => ExternalReferenceListBuilder::class,
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => ExternalReferenceAccessControlHandler::class,
    'form' => [
      'default' => ExternalReferenceForm::class,
      'add' => ExternalReferenceForm::class,
      'edit' => ExternalReferenceForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  base_table: 'external_reference',
  data_table: 'external_reference_field_data',
  revision_table: 'external_reference_revision',
  revision_data_table: 'external_reference_field_revision',
  show_revision_ui: TRUE,
  translatable: FALSE,
  admin_permission: 'administer external references',
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
  constraints: [
    'ExternalReferenceIdentity' => [],
    'ProcessDevelopmentEditable' => [],
  ],
  links: [
    'canonical' => '/external/{external_reference}',
    'add-form' => '/external/add',
    'edit-form' => '/external/{external_reference}/edit',
    'delete-form' => '/external/{external_reference}/delete',
    'version-history' => '/external/{external_reference}/revisions',
    'revision' => '/external/{external_reference}/revisions/{external_reference_revision}/view',
    'revision-revert-form' => '/external/{external_reference}/revisions/{external_reference_revision}/revert',
    'collection' => '/external',
  ],
)]
class ExternalReference extends RevisionableContentEntityBase implements EntityChangedInterface, EntityOwnerInterface {

  use DevelopmentScopedTrait;
  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * Fields that identify the external work.
   */
  const IDENTITY = ['provider', 'host', 'namespace', 'kind', 'remote_id'];

  /**
   * Sets the identity, canonical link and, if empty, the label.
   */
  public function setIdentity(ExternalIdentity $identity): static {
    foreach ($identity->fields() as $field => $value) {
      $this->set($field, $value);
    }
    $this->set('url', $identity->url);
    if (trim((string) $this->get('label')->value) === '') {
      $this->set('label', $identity->label);
    }
    return $this;
  }

  /**
   * The identity fields as stored.
   *
   * @return array<string, string>
   */
  public function identityValues(): array {
    $values = [];
    foreach (static::IDENTITY as $field) {
      $values[$field] = (string) $this->get($field)->value;
    }
    return $values;
  }

  /**
   * The canonical link to the external work.
   */
  public function getUrl(): string {
    return (string) $this->get('url')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $references = \Drupal::service('divengine_external.references');
    if ($this->isNew() && $this->get('provider')->isEmpty()) {
      // A writer that only gives the link: read the identity from it.
      $this->setIdentity($references->identify((string) $this->get('url')->value));
    }
    $this->set('label', trim((string) $this->get('label')->value));
    if (!$this->isNew()) {
      $original = $storage->loadUnchanged($this->id());
      if ($original instanceof self && ($original->identityValues() !== $this->identityValues() || $original->getUrl() !== $this->getUrl() || (string) $original->get('development')->target_id !== (string) $this->get('development')->target_id)) {
        throw new EntityStorageException('The identity and Development of an external reference cannot change; register a new reference instead.');
      }
      // Like trace links, every change is a revision, by whoever saves it
      // unless the writer named the revision's author.
      if (!$this->isNewRevision()) {
        $this->setRevisionUserId((int) \Drupal::currentUser()->id());
        $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      }
      $this->setNewRevision(TRUE);
    }
    else {
      $references->assertUnique($this);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    foreach ($entities as $reference) {
      if (\Drupal::service('divengine_external.references')->traceCount($reference)) {
        throw new EntityStorageException(sprintf('External reference %s still has trace links; remove them first.', $reference->id()));
      }
    }
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Registered by'));

    $fields['development'] = static::developmentFieldDefinition()
      ->setDescription(new TranslatableMarkup('The Development that references this work. Only people who see it see the reference.'));

    $fields['url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Link'))
      ->setDescription(new TranslatableMarkup('The link to the issue, pull request, commit or page, e.g. https://github.com/owner/repository/pull/12.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 2048)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10]);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('How Studio names it. Left empty, it is read from the link (owner/repository#12).'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5]);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDescription(new TranslatableMarkup("Studio's own notes on this work. The external state of the work belongs to its provider."))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 0]);

    foreach ([
      'provider' => [new TranslatableMarkup('Provider'), 32],
      'host' => [new TranslatableMarkup('Host'), 253],
      'namespace' => [new TranslatableMarkup('Namespace'), 255],
      'kind' => [new TranslatableMarkup('Kind'), 32],
      'remote_id' => [new TranslatableMarkup('Remote identifier'), 128],
    ] as $name => [$label, $length]) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel($label)
        ->setSetting('max_length', $length)
        ->setReadOnly(TRUE);
    }

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Registered on'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setRevisionable(TRUE);
    return $fields;
  }

}
