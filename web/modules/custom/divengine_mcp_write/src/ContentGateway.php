<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\divengine_mcp_write\Entity\McpContentCapability;
use Drupal\divengine_traceability\CodeResolver;
use Psr\Log\LoggerInterface;

/**
 * Generic, administrator-configured content operations for MCP (#86).
 *
 * Every call needs an enabled capability for the bundle and operation (see
 * McpContentCapability) and then passes Drupal's own checks as the person:
 * entity and field access, the Development's access, entity validation.
 * Records are addressed by UUID. Records the person cannot see answer like
 * missing ones. New records are saved unpublished and owned by the person;
 * clients never set ownership, publication, revisions or, after creation,
 * the Development or language. Changes made by the model's own hooks to
 * those are refused and rolled back.
 */
class ContentGateway {

  /**
   * Largest page of a list.
   */
  const MAX_LIMIT = 50;

  /**
   * How long an idempotency key is remembered, in seconds.
   */
  const IDEMPOTENCY_TTL = 7 * 86400;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ContentModels $models,
    protected CodeResolver $codes,
    protected LanguageManagerInterface $languageManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected Connection $database,
    protected KeyValueExpirableFactoryInterface $keyValue,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * Sound capabilities that enable an operation, by ID.
   *
   * @return \Drupal\divengine_mcp_write\Entity\McpContentCapability[]
   */
  public function capabilities(?string $operation = NULL): array {
    $out = [];
    foreach ($this->entityTypeManager->getStorage('mcp_content_capability')->loadMultiple() as $id => $capability) {
      /** @var \Drupal\divengine_mcp_write\Entity\McpContentCapability $capability */
      if ($capability->status() && ($operation === NULL || $capability->allows($operation)) && !$this->models->capabilityProblems($capability)) {
        $out[$id] = $capability;
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * What clients may do, per bundle, and what this person may do now.
   */
  public function describeCapabilities(AccountInterface $account, bool $may_write): array {
    $out = [];
    foreach ($this->capabilities('read') as $id => $capability) {
      $entity_type_id = $capability->getTargetEntityTypeId();
      $bundle = $capability->getTargetBundle();
      $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
      $fields = [];
      foreach ($capability->getWritableFields() as $name) {
        $definition = $definitions[$name];
        $fields[$name] = array_filter([
          'label' => (string) $definition->getLabel(),
          'type' => $definition->getType(),
          'required' => $definition->isRequired(),
          'multiple' => $definition->getFieldStorageDefinition()->isMultiple(),
          'allowed_values' => $definition->getType() !== 'entity_reference' ? array_keys((array) $definition->getSetting('allowed_values')) : NULL,
          'target' => $definition->getType() === 'entity_reference' ? $definition->getSetting('target_type') : NULL,
        ], fn ($value) => $value !== NULL && $value !== []);
      }
      $operations = $capability->getOperations();
      $out[] = [
        'type' => $id,
        'label' => (string) ($this->entityTypeManager->getDefinition($entity_type_id)->getBundleLabel() ?? $entity_type_id) . ': ' . $capability->label(),
        'operations' => $operations,
        'writable_fields' => $fields,
        'development' => $capability->getDevelopmentField() === '' ? 'none' : ($capability->requiresDevelopment() ? 'required on create' : 'optional on create'),
        'languages' => array_keys($this->languageManager->getLanguages()),
        'new_records' => 'unpublished, owned by you',
        'you_may' => array_values(array_filter($operations, fn ($operation) => $operation === 'read' || ($may_write && $this->mayWrite($capability, $operation, $account)))),
      ];
    }
    return $out;
  }

  /**
   * A page of the records of a bundle the person may see.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function list(string $type, AccountInterface $account, int $limit = 20, int $offset = 0, string $development = ''): array {
    $capability = $this->capability($type, 'read');
    $entity_type = $this->entityTypeManager->getDefinition($capability->getTargetEntityTypeId());
    $storage = $this->entityTypeManager->getStorage($entity_type->id());
    $limit = max(1, min(static::MAX_LIMIT, $limit));
    $offset = max(0, $offset);
    $id_key = $entity_type->getKey('id');
    $base = function (bool $access_check) use ($storage, $entity_type, $capability, $id_key) {
      return $storage->getQuery()->accessCheck($access_check)
        ->condition($entity_type->getKey('bundle'), $capability->getTargetBundle())
        ->sort($id_key, 'DESC');
    };
    $development_id = NULL;
    if ($development !== '') {
      if ($capability->getDevelopmentField() === '') {
        throw new EngineeringWriteException(sprintf('%s records have no Development.', $type));
      }
      $development_id = $this->development($development, $account, FALSE)->id();
    }
    $window = $offset + $limit + 1;
    // Queries apply node grants and similar rules for the current user.
    // Grants usually leave out unpublished records, even the person's own
    // drafts, so those are queried separately; every record is then checked
    // with entity view access before it is listed.
    $ids = $this->asPerson($account, function () use ($base, $capability, $development_id, $window, $entity_type, $account) {
      $queries = [$base(TRUE)];
      if ($entity_type->hasKey('owner') && $entity_type->hasKey('published')) {
        $queries[] = $base(FALSE)->condition($entity_type->getKey('owner'), $account->id())->condition($entity_type->getKey('published'), 0);
      }
      $ids = [];
      foreach ($queries as $query) {
        if ($development_id !== NULL) {
          $query->condition($capability->getDevelopmentField(), $development_id);
        }
        foreach ($query->range(0, $window)->execute() as $id) {
          $ids[(int) $id] = $id;
        }
      }
      krsort($ids);
      return $ids;
    });
    $records = [];
    $seen = 0;
    $more = FALSE;
    foreach ($storage->loadMultiple(array_keys($ids)) as $entity) {
      if (!$entity->access('view', $account)) {
        continue;
      }
      if ($seen++ < $offset) {
        continue;
      }
      if (count($records) === $limit) {
        $more = TRUE;
        break;
      }
      $records[] = $this->summary($entity, $capability);
    }
    return ['type' => $type, 'offset' => $offset, 'limit' => $limit, 'more' => $more, 'records' => $records];
  }

  /**
   * One record the person may see, with its readable fields.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function read(string $type, string $id, AccountInterface $account): array {
    $capability = $this->capability($type, 'read');
    return $this->describe($this->visible($capability, $id, $account), $capability, $account);
  }

  /**
   * Creates a record, unpublished and owned by the person.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function create(string $type, array $values, AccountInterface $account, string $client, string $development = '', string $langcode = '', string $log = '', string $idempotency_key = ''): array {
    $capability = $this->capability($type, 'create');
    $request = hash('sha256', json_encode([$type, $values, $development, $langcode]));
    $development_entity = $this->developmentFor($capability, $development, $account);
    if ($replayed = $this->replay($idempotency_key, $request, $capability, $account)) {
      return $replayed;
    }
    $entity_type = $this->entityTypeManager->getDefinition($capability->getTargetEntityTypeId());
    $storage = $this->entityTypeManager->getStorage($entity_type->id());
    $initial = [$entity_type->getKey('bundle') => $capability->getTargetBundle()];
    if ($langcode !== '') {
      if (!$this->languageManager->getLanguage($langcode) || $this->languageManager->getLanguage($langcode)->isLocked()) {
        throw new EngineeringWriteException(sprintf('Unknown language %s. Use one of: %s.', $langcode, implode(', ', array_keys($this->languageManager->getLanguages()))));
      }
      $initial[$entity_type->getKey('langcode')] = $langcode;
    }
    /** @var \Drupal\Core\Entity\ContentEntityInterface&\Drupal\user\EntityOwnerInterface&\Drupal\Core\Entity\EntityPublishedInterface&\Drupal\Core\Entity\RevisionLogInterface $entity */
    $entity = $storage->create($initial);
    if (!$this->entityTypeManager->getAccessControlHandler($entity_type->id())->createAccess($capability->getTargetBundle(), $account)) {
      throw new EngineeringWriteException(sprintf('You may not create %s records.', $type));
    }
    $entity->setOwnerId((int) $account->id());
    $entity->setUnpublished();
    if ($development_entity) {
      $entity->set($capability->getDevelopmentField(), $development_entity->id());
    }
    $this->apply($entity, $capability, $values, $account);
    $this->revision($entity, $account, $client, $log ?: 'Created.');
    $this->save($entity, $account, function (ContentEntityInterface $saved) use ($capability, $development_entity, $account) {
      $this->assertKept($saved, $capability, $development_entity?->id(), FALSE, $account);
    });
    $this->remember($idempotency_key, $request, $entity, $account);
    $this->audit('create', $entity, $account, $client);
    return $this->describe($entity, $capability, $account);
  }

  /**
   * Revises a record: always a new revision, never its publication.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function update(string $type, string $id, array $values, AccountInterface $account, string $client, string $log, ?int $base_revision = NULL): array {
    $capability = $this->capability($type, 'update');
    if (trim($log) === '') {
      throw new EngineeringWriteException('Say why it changes (log).');
    }
    $entity = $this->visible($capability, $id, $account);
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $latest = (int) $storage->getLatestRevisionId($entity->id());
    if ($base_revision !== NULL && $base_revision !== $latest) {
      throw new EngineeringWriteException(sprintf('The record changed after revision %d; it is now at revision %d. Read it again before revising.', $base_revision, $latest));
    }
    /** @var \Drupal\Core\Entity\ContentEntityInterface&\Drupal\Core\Entity\EntityPublishedInterface&\Drupal\Core\Entity\RevisionLogInterface $working */
    $working = $storage->loadRevision($latest);
    if (!$working->access('update', $account)) {
      throw new EngineeringWriteException('You may not revise this record.');
    }
    $development = $capability->getDevelopmentField() !== '' ? $working->get($capability->getDevelopmentField())->target_id : NULL;
    $published = $working->isPublished();
    $this->apply($working, $capability, $values, $account);
    $working->setNewRevision(TRUE);
    $this->revision($working, $account, $client, $log);
    $this->save($working, $account, function (ContentEntityInterface $saved) use ($capability, $development, $published, $account) {
      $this->assertKept($saved, $capability, $development, $published, $account);
    });
    $this->audit('update', $working, $account, $client);
    return $this->describe($working, $capability, $account);
  }

  /**
   * Deletes a record; the caller must have confirmed it.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function delete(string $type, string $id, bool $confirm, AccountInterface $account, string $client): array {
    $capability = $this->capability($type, 'delete');
    $entity = $this->visible($capability, $id, $account);
    if (!$confirm) {
      throw new EngineeringWriteException('Deleting cannot be undone from MCP. Ask the person, then call again with confirm: true.');
    }
    if (!$entity->access('delete', $account)) {
      throw new EngineeringWriteException('You may not delete this record.');
    }
    $label = $entity->label();
    try {
      $this->asPerson($account, fn () => $entity->delete());
    }
    catch (EntityStorageException $e) {
      throw new EngineeringWriteException('The record cannot be deleted: ' . $e->getMessage(), 0, $e);
    }
    $this->audit('delete', $entity, $account, $client);
    return ['deleted' => $entity->uuid(), 'label' => $label];
  }

  /**
   * Whether the person could do a write operation on the bundle now.
   */
  protected function mayWrite(McpContentCapability $capability, string $operation, AccountInterface $account): bool {
    if ($operation === 'create') {
      return $this->entityTypeManager->getAccessControlHandler($capability->getTargetEntityTypeId())->createAccess($capability->getTargetBundle(), $account);
    }
    // Update and delete depend on each record; report the configuration.
    return TRUE;
  }

  /**
   * An enabled, sound capability for an operation.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function capability(string $type, string $operation): McpContentCapability {
    $capabilities = $this->capabilities($operation);
    if (!isset($capabilities[$type])) {
      $enabled = array_keys($capabilities);
      throw new EngineeringWriteException(sprintf('%s is not enabled for %s. Enabled: %s.', ucfirst($operation), mb_substr($type, 0, 80), $enabled ? implode(', ', $enabled) : 'none'));
    }
    return $capabilities[$type];
  }

  /**
   * A record of the capability's bundle the person may see, by UUID.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function visible(McpContentCapability $capability, string $id, AccountInterface $account): ContentEntityInterface {
    $missing = new EngineeringWriteException(sprintf('No %s record with id %s is visible to you.', $capability->id(), mb_substr($id, 0, 40)));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
      throw $missing;
    }
    $entities = $this->entityTypeManager->getStorage($capability->getTargetEntityTypeId())->loadByProperties(['uuid' => $id]);
    $entity = reset($entities);
    if (!$entity instanceof ContentEntityInterface || $entity->bundle() !== $capability->getTargetBundle() || !$entity->access('view', $account)) {
      throw $missing;
    }
    return $entity;
  }

  /**
   * The Development a new record goes to, checked, or NULL.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function developmentFor(McpContentCapability $capability, string $code, AccountInterface $account): ?ContentEntityInterface {
    if ($capability->getDevelopmentField() === '') {
      if ($code !== '') {
        throw new EngineeringWriteException(sprintf('%s records have no Development.', $capability->id()));
      }
      return NULL;
    }
    if ($code === '') {
      if ($capability->requiresDevelopment()) {
        throw new EngineeringWriteException(sprintf('%s records need a Development: pass its code.', $capability->id()));
      }
      return NULL;
    }
    return $this->development($code, $account, TRUE);
  }

  /**
   * A Development by code that the person sees (and may add records to).
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function development(string $code, AccountInterface $account, bool $update): ContentEntityInterface {
    $development = $this->codes->resolve($code);
    if (!$development || $development->getEntityTypeId() !== 'development' || !$development->access('view', $account)) {
      throw new EngineeringWriteException(sprintf('No Development with code %s is visible to you.', mb_strtoupper(mb_substr(trim($code), 0, 40))));
    }
    if ($update && !$development->access('contribute', $account)) {
      throw new EngineeringWriteException('You cannot add records to this Development.');
    }
    return $development;
  }

  /**
   * Sets the values clients sent, only on writable fields they may edit.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function apply(ContentEntityInterface $entity, McpContentCapability $capability, array $values, AccountInterface $account): void {
    $writable = $capability->getWritableFields();
    if ($refused = array_diff(array_keys($values), $writable)) {
      throw new EngineeringWriteException(sprintf('These fields cannot be set: %s. Writable: %s.', implode(', ', array_map('strval', $refused)), implode(', ', $writable) ?: 'none'));
    }
    $type = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
    foreach ($values as $name => $value) {
      $definition = $entity->getFieldDefinition($name);
      // Checked again at every call: the configuration is not trusted to
      // still match the model.
      if (!$definition || ($problem = $this->models->fieldProblem($type, $definition))) {
        throw new EngineeringWriteException(sprintf('Field %s cannot be set: %s', $name, $problem ?? 'it does not exist.'));
      }
      if (!$entity->get($name)->access('edit', $account)) {
        throw new EngineeringWriteException(sprintf('You may not edit field %s.', $name));
      }
      $entity->set($name, $this->convert($definition, $value, $account));
    }
  }

  /**
   * Converts a client value to field item values.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function convert($definition, mixed $value, AccountInterface $account): array {
    $name = $definition->getName();
    $multiple = $definition->getFieldStorageDefinition()->isMultiple();
    $items = $value === NULL ? [] : (is_array($value) && array_is_list($value) ? $value : [$value]);
    if (!$multiple && count($items) > 1) {
      throw new EngineeringWriteException(sprintf('Field %s takes one value.', $name));
    }
    $out = [];
    foreach ($items as $item) {
      $out[] = match ($definition->getType()) {
        'text', 'text_long', 'text_with_summary' => $this->text($name, $item, $definition->getType() === 'text_with_summary', $account),
        'entity_reference' => ['target_id' => $this->reference($name, $definition->getSetting('target_type'), $item, $account)],
        'boolean' => ['value' => is_bool($item) ? (int) $item : throw new EngineeringWriteException(sprintf('Field %s takes true or false.', $name))],
        default => ['value' => is_scalar($item) ? $item : throw new EngineeringWriteException(sprintf('Field %s takes plain values.', $name))],
      };
    }
    return $out;
  }

  /**
   * A formatted text value in a format the person may use.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function text(string $name, mixed $item, bool $summary, AccountInterface $account): array {
    $item = is_string($item) ? ['value' => $item] : $item;
    if (!is_array($item) || !is_string($item['value'] ?? NULL) || array_diff(array_keys($item), ['value', 'format', 'summary'])) {
      throw new EngineeringWriteException(sprintf('Field %s takes text, or {value, format%s}.', $name, $summary ? ', summary' : ''));
    }
    $formats = filter_formats($account);
    $format = (string) ($item['format'] ?? filter_default_format($account));
    if (!isset($formats[$format])) {
      throw new EngineeringWriteException(sprintf('You may not use text format %s. Use one of: %s.', mb_substr($format, 0, 40), implode(', ', array_keys($formats))));
    }
    return array_filter(['value' => $item['value'], 'format' => $format, 'summary' => $summary ? ($item['summary'] ?? NULL) : NULL], fn ($v) => $v !== NULL);
  }

  /**
   * The ID of a referenced record the person may see, given by UUID.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function reference(string $name, string $target_type, mixed $item, AccountInterface $account): int|string {
    $uuid = is_array($item) ? ($item['id'] ?? NULL) : $item;
    $missing = new EngineeringWriteException(sprintf('Field %s: no %s with id %s is visible to you.', $name, $target_type, is_string($uuid) ? mb_substr($uuid, 0, 40) : '?'));
    if (!is_string($uuid) || !preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
      throw $missing;
    }
    $targets = $this->entityTypeManager->getStorage($target_type)->loadByProperties(['uuid' => $uuid]);
    $target = reset($targets);
    if (!$target || !$target->access('view', $account)) {
      throw $missing;
    }
    return $target->id();
  }

  protected function revision(ContentEntityInterface $entity, AccountInterface $account, string $client, string $log): void {
    /** @var \Drupal\Core\Entity\RevisionLogInterface $entity */
    $entity->setRevisionUserId((int) $account->id());
    $entity->setRevisionCreationTime($this->time->getRequestTime());
    $entity->setRevisionLogMessage(mb_substr(sprintf('Through MCP (%s): %s', $client, trim($log) ?: 'No reason given.'), 0, 1000));
  }

  /**
   * Validates and saves as the person, in a transaction, then checks what
   * the model's hooks did; anything refused is rolled back.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function save(ContentEntityInterface $entity, AccountInterface $account, callable $check): void {
    $this->asPerson($account, function () use ($entity, $check) {
      $violations = $entity->validate();
      if ($violations->count()) {
        $messages = [];
        foreach ($violations as $violation) {
          $field = strtok((string) $violation->getPropertyPath(), '.');
          $messages[] = ($field ? "$field: " : '') . strip_tags((string) $violation->getMessage());
        }
        throw new EngineeringWriteException(implode(' ', array_unique($messages)));
      }
      $transaction = $this->database->startTransaction();
      try {
        $entity->save();
        $check($entity);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        $this->entityTypeManager->getStorage($entity->getEntityTypeId())->resetCache();
        throw $e instanceof EngineeringWriteException ? $e : new EngineeringWriteException($e instanceof EntityStorageException ? $e->getMessage() : 'The record could not be saved.', 0, $e);
      }
    });
  }

  /**
   * Refuses a save after which the Development, the publication or the
   * Development's access are not what was intended.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function assertKept(ContentEntityInterface $saved, McpContentCapability $capability, int|string|null $development, bool $published, AccountInterface $account): void {
    /** @var \Drupal\Core\Entity\EntityPublishedInterface $saved */
    if ($saved->isPublished() !== $published) {
      throw new EngineeringWriteException('Saving would change whether the record is published; that is decided in Studio.');
    }
    $field = $capability->getDevelopmentField();
    if ($field === '') {
      return;
    }
    $now = $saved->get($field)->target_id;
    if ((string) $now !== (string) $development) {
      throw new EngineeringWriteException('Saving would put the record in another Development (for example through its parent); records keep the Development they were created in.');
    }
    if ($now && !$saved->get($field)->entity?->access('contribute', $account)) {
      throw new EngineeringWriteException('You cannot add records to this Development.');
    }
  }

  /**
   * What a list shows of a record.
   */
  protected function summary(ContentEntityInterface $entity, McpContentCapability $capability): array {
    /** @var \Drupal\Core\Entity\EntityPublishedInterface $entity */
    $field = $capability->getDevelopmentField();
    return array_filter([
      'id' => $entity->uuid(),
      'label' => $entity->label(),
      'langcode' => $entity->language()->getId(),
      'published' => $entity->isPublished(),
      'development' => $field !== '' ? $entity->get($field)->entity?->get('code')->value : NULL,
    ], fn ($value) => $value !== NULL);
  }

  /**
   * What a client reads back: the latest revision's readable fields.
   */
  protected function describe(ContentEntityInterface $entity, McpContentCapability $capability, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    /** @var \Drupal\Core\Entity\ContentEntityInterface&\Drupal\Core\Entity\RevisionLogInterface $latest */
    $latest = $storage->loadRevision($storage->getLatestRevisionId($entity->id()));
    $fields = [];
    $type = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
    foreach ($this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle()) as $name => $definition) {
      if ($name === $capability->getDevelopmentField() || $this->models->fieldProblem($type, $definition) !== NULL || !$latest->get($name)->access('view', $account)) {
        continue;
      }
      $values = [];
      foreach ($latest->get($name) as $item) {
        if ($definition->getType() === 'entity_reference') {
          $target = $item->entity;
          // Hidden targets are left out, not named.
          if ($target && $target->access('view', $account)) {
            $values[] = ['id' => $target->uuid(), 'label' => $target->label()];
          }
        }
        elseif (in_array($definition->getType(), ['text', 'text_long', 'text_with_summary'], TRUE)) {
          $values[] = array_filter(['value' => $item->value, 'format' => $item->format, 'summary' => $item->summary ?? NULL], fn ($v) => $v !== NULL && $v !== '');
        }
        else {
          $values[] = $item->value;
        }
      }
      $fields[$name] = $definition->getFieldStorageDefinition()->isMultiple() ? $values : ($values[0] ?? NULL);
    }
    return $this->summary($latest, $capability) + [
      'type' => $capability->id(),
      'revision' => (int) $latest->getRevisionId(),
      'revision_log' => $latest->getRevisionLogMessage(),
      'fields' => $fields,
    ];
  }

  /**
   * Runs code as the person, so that queries and validation see them.
   */
  protected function asPerson(AccountInterface $account, callable $code): mixed {
    $this->accountSwitcher->switchTo($account);
    try {
      return $code();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * The record an earlier create with the same idempotency key made, as the
   * person may see it now; nothing is created again.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function replay(string $key, string $request, McpContentCapability $capability, AccountInterface $account): ?array {
    if ($key === '') {
      return NULL;
    }
    $stored = $this->keyValue->get('divengine_mcp_write.content_idempotency')->get($account->id() . ':' . $key);
    if (!$stored) {
      return NULL;
    }
    if (($stored['request'] ?? NULL) !== $request) {
      throw new EngineeringWriteException('This idempotency key was used for a different request.');
    }
    $entity = $this->entityTypeManager->getStorage($capability->getTargetEntityTypeId())->load($stored['id'] ?? 0);
    if (!$entity || !$entity->access('view', $account)) {
      throw new EngineeringWriteException('This idempotency key was already used, and what it created is not available to you. Use a new key to create a record.');
    }
    return $this->describe($entity, $capability, $account) + ['replayed' => TRUE];
  }

  protected function remember(string $key, string $request, ContentEntityInterface $entity, AccountInterface $account): void {
    if ($key !== '') {
      $this->keyValue->get('divengine_mcp_write.content_idempotency')->setWithExpire($account->id() . ':' . $key, ['request' => $request, 'id' => $entity->id()], static::IDEMPOTENCY_TTL);
    }
  }

  /**
   * Logs who wrote what through which client, never the content.
   */
  protected function audit(string $operation, ContentEntityInterface $entity, AccountInterface $account, string $client): void {
    $this->logger->notice('content @operation @type @bundle @id by user @uid through MCP client @client.', [
      '@operation' => $operation,
      '@type' => $entity->getEntityTypeId(),
      '@bundle' => $entity->bundle(),
      '@id' => $entity->id(),
      '@uid' => $account->id(),
      '@client' => $client,
    ]);
  }

}
