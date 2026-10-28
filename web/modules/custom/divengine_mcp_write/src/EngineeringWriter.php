<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\ExternalReferenceException;
use Drupal\divengine_external\ExternalReferences;
use Drupal\divengine_external\InvalidExternalUrlException;
use Drupal\divengine_traceability\CodeResolver;
use Drupal\divengine_traceability\Entity\TraceLinkType;
use Psr\Log\LoggerInterface;

/**
 * The engineering writes MCP clients may make, as the person they act for.
 *
 * Every operation is enumerated here; there is no generic entity write. Each
 * one goes through the Entity API with the person's access, validation and
 * the trace rules, writes a new revision whose log names the client, and is
 * logged without its content. Records the person cannot see answer like
 * missing ones. Nothing is approved: artifacts stay in Draft, Review or
 * Proposed.
 */
class EngineeringWriter {

  /**
   * Artifact types clients create and revise.
   */
  const TYPES = ['requirement', 'decision', 'evidence'];

  /**
   * Fields of a type that clients set, by argument name.
   *
   * Requirements need a statement; the list fields take the values the
   * Studio form offers and are checked by entity validation.
   */
  const FIELDS = [
    'requirement' => [
      'statement' => 'field_statement',
      'rationale' => 'field_rationale',
      'source' => 'field_source',
      'acceptance_notes' => 'field_acceptance_notes',
      'requirement_type' => 'field_requirement_type',
      'priority' => 'field_priority',
      'risk' => 'field_risk',
    ],
  ];

  /**
   * Moderation states clients may set; approving stays with people.
   */
  const STATES = ['draft', 'review', 'proposed'];

  /**
   * How long an idempotency key is remembered, in seconds.
   */
  const IDEMPOTENCY_TTL = 7 * 86400;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CodeResolver $codes,
    protected ExternalReferences $references,
    protected KeyValueExpirableFactoryInterface $keyValue,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * The Developments the person sees, and whether they may add records.
   */
  public function developments(AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('development');
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('code')->execute();
    $out = [];
    foreach ($storage->loadMultiple($ids) as $development) {
      $out[] = [
        'code' => $development->get('code')->value,
        'name' => $development->get('name')->value,
        'writable' => $development->access('update', $account),
      ];
    }
    return $out;
  }

  /**
   * A record by its code, with its traces, as the person may see them.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function read(string $code, AccountInterface $account): array {
    return $this->describe($this->visible($code, $account), $account);
  }

  /**
   * Creates a requirement, decision or evidence in a Development.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function createArtifact(string $development_code, string $type, array $values, AccountInterface $account, string $client, string $idempotency_key = ''): array {
    if (!in_array($type, static::TYPES, TRUE)) {
      throw new EngineeringWriteException(sprintf('Only these types can be created: %s.', implode(', ', static::TYPES)));
    }
    $request = hash('sha256', json_encode([$development_code, $type, $values]));
    // A retry passes the same checks as the first request: a person who no
    // longer may add records to the Development learns nothing from it.
    $development = $this->development($development_code, $account);
    if ($replayed = $this->replay($idempotency_key, $request, $account)) {
      return $replayed;
    }
    $state = $this->state($values['state'] ?? 'draft');
    $artifact = Artifact::create([
      'type' => $type,
      'development' => $development->id(),
      'uid' => $account->id(),
      'moderation_state' => $state,
    ]);
    $this->apply($artifact, $values);
    $artifact->setRevisionUserId((int) $account->id());
    $artifact->setRevisionCreationTime($this->time->getRequestTime());
    $artifact->setRevisionLogMessage($this->log($client, $values['log'] ?? 'Created.'));
    $this->save($artifact, $account, 'create');
    $result = $this->describe($artifact, $account);
    $this->remember($idempotency_key, $request, $artifact, $account);
    $this->audit('create_artifact', $artifact, $account, $client);
    return $result;
  }

  /**
   * Revises a requirement, decision or evidence: always a new revision.
   *
   * @param int|null $base_revision
   *   The revision the client read; the update is refused if the artifact
   *   changed since.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function updateArtifact(string $code, array $values, AccountInterface $account, string $client, ?int $base_revision = NULL): array {
    $artifact = $this->visible($code, $account);
    if (!$artifact instanceof Artifact || !in_array($artifact->bundle(), static::TYPES, TRUE)) {
      throw new EngineeringWriteException(sprintf('Only these types can be revised: %s.', implode(', ', static::TYPES)));
    }
    if (trim((string) ($values['log'] ?? '')) === '') {
      throw new EngineeringWriteException('Say why it changes (log).');
    }
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('artifact');
    $latest_id = (int) $storage->getLatestRevisionId($artifact->id());
    if ($base_revision !== NULL && $base_revision !== $latest_id) {
      throw new EngineeringWriteException(sprintf('%s changed after revision %d; it is now at revision %d. Read it again before revising.', $code, $base_revision, $latest_id));
    }
    /** @var \Drupal\divengine_artifact\Entity\Artifact $working */
    $working = $storage->loadRevision($latest_id);
    // A revision is a draft unless the client submits it. The workflow
    // decides what stays the default: a baselined requirement keeps its
    // baselined revision until people baseline the new one.
    $state = $this->state($values['state'] ?? 'draft');
    $working->set('moderation_state', $state);
    $this->apply($working, $values);
    $working->setNewRevision(TRUE);
    $working->setRevisionUserId((int) $account->id());
    $working->setRevisionCreationTime($this->time->getRequestTime());
    $working->setRevisionLogMessage($this->log($client, (string) $values['log']));
    $this->save($working, $account, 'update');
    $this->audit('update_artifact', $working, $account, $client);
    return $this->describe($working, $account);
  }

  /**
   * Registers external work in a Development, or finds the reference.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function registerExternal(string $development_code, string $url, AccountInterface $account, string $client, string $label = '', string $notes = ''): array {
    $development = $this->development($development_code, $account);
    try {
      [$reference, $created] = $this->references->resolve($development->id(), $url, $account, $label);
    }
    catch (ExternalReferenceException | InvalidExternalUrlException $e) {
      throw new EngineeringWriteException($e->getMessage(), 0, $e);
    }
    if ($created && trim($notes) !== '') {
      $reference->set('notes', trim($notes));
      $reference->setNewRevision(TRUE);
      $reference->setRevisionUserId((int) $account->id());
      $reference->setRevisionLogMessage($this->log($client, 'Notes.'));
      $this->save($reference, $account, 'update');
    }
    if ($created) {
      $this->audit('register_external', $reference, $account, $client);
    }
    return ['created' => $created] + $this->describe($reference, $account);
  }

  /**
   * Links two records with a typed trace link.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function link(string $source_code, string $type, string $target_code, AccountInterface $account, string $client, string $rationale = ''): array {
    if (!isset(TraceLinkType::allowedValues()[$type])) {
      throw new EngineeringWriteException(sprintf('Unknown relationship "%s". Use one of: %s.', $type, implode(', ', array_keys(TraceLinkType::allowedValues()))));
    }
    $source = $this->linkable($source_code, $account);
    $target = $this->linkable($target_code, $account);
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('link_type', $type)
      ->condition('source_entity_type', $source->getEntityTypeId())
      ->condition('source_entity_id', $source->id())
      ->condition('target_entity_type', $target->getEntityTypeId())
      ->condition('target_entity_id', $target->id())
      ->range(0, 1)
      ->execute();
    if ($ids) {
      return ['created' => FALSE, 'trace_link' => (int) reset($ids), 'link' => "$source_code $type $target_code"];
    }
    if (!$this->entityTypeManager->getAccessControlHandler('trace_link')->createAccess(NULL, $account)) {
      throw new EngineeringWriteException('You may not create trace links.');
    }
    /** @var \Drupal\divengine_traceability\Entity\TraceLink $link */
    $link = $storage->create([
      'link_type' => $type,
      'source_entity_type' => $source->getEntityTypeId(),
      'source_entity_id' => $source->id(),
      'target_entity_type' => $target->getEntityTypeId(),
      'target_entity_id' => $target->id(),
      'rationale' => mb_substr(trim($rationale), 0, 2000),
      'uid' => $account->id(),
      'revision_log' => $this->log($client, 'Linked.'),
    ]);
    $this->save($link, $account, 'create');
    $this->audit('link', $link, $account, $client);
    return ['created' => TRUE, 'trace_link' => (int) $link->id(), 'link' => "$source_code $type $target_code"];
  }

  /**
   * Removes a trace link; the caller must have confirmed it.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  public function unlink(int $id, bool $confirm, AccountInterface $account, string $client): array {
    if (!$confirm) {
      throw new EngineeringWriteException('Removing a trace link cannot be undone from MCP. Ask the person, then call again with confirm: true.');
    }
    $link = $this->entityTypeManager->getStorage('trace_link')->load($id);
    if (!$link || !$link->access('view', $account)) {
      throw new EngineeringWriteException('No trace link with that number is visible to you.');
    }
    $source = $link->getSourceEntity();
    $target = $link->getTargetEntity();
    if (!$this->isLinkable($source) || !$this->isLinkable($target)) {
      throw new EngineeringWriteException('Only links between requirements, decisions, evidence and external references can be removed from MCP.');
    }
    if (!$link->access('delete', $account)) {
      throw new EngineeringWriteException('You may not remove this trace link.');
    }
    $label = $link->label();
    try {
      $link->delete();
    }
    catch (EntityStorageException $e) {
      throw new EngineeringWriteException('The trace link cannot be removed: ' . $e->getMessage(), 0, $e);
    }
    $this->audit('unlink', $link, $account, $client);
    return ['removed' => $id, 'link' => $label];
  }

  /**
   * A record with a code that the person sees, or an error that says no
   * more than for a missing one.
   */
  protected function visible(string $code, AccountInterface $account): ContentEntityInterface {
    $entity = $this->codes->resolve($code);
    if (!$entity || !$entity->access('view', $account)) {
      throw new EngineeringWriteException(sprintf('No record with code %s is visible to you.', mb_strtoupper(mb_substr(trim($code), 0, 40))));
    }
    return $entity;
  }

  /**
   * A record that MCP may link: a requirement, decision, evidence or
   * external reference the person sees.
   */
  protected function linkable(string $code, AccountInterface $account): ContentEntityInterface {
    $entity = $this->visible($code, $account);
    if (!$this->isLinkable($entity)) {
      throw new EngineeringWriteException('Only requirements, decisions, evidence and external references can be linked from MCP.');
    }
    return $entity;
  }

  protected function isLinkable(?ContentEntityInterface $entity): bool {
    return $entity instanceof ExternalReference || ($entity instanceof Artifact && in_array($entity->bundle(), static::TYPES, TRUE));
  }

  /**
   * A Development the person may add records to.
   */
  protected function development(string $code, AccountInterface $account): ContentEntityInterface {
    $development = $this->codes->resolve($code);
    if (!$development || $development->getEntityTypeId() !== 'development' || !$development->access('view', $account)) {
      throw new EngineeringWriteException(sprintf('No Development with code %s is visible to you.', mb_strtoupper(mb_substr(trim($code), 0, 40))));
    }
    if (!$development->access('update', $account)) {
      throw new EngineeringWriteException('You cannot add records to this Development.');
    }
    return $development;
  }

  /**
   * A state clients may set.
   */
  protected function state(string $state): string {
    if (!in_array($state, static::STATES, TRUE)) {
      throw new EngineeringWriteException(sprintf('Clients may only set these states: %s. Approving stays with people, in Studio.', implode(', ', static::STATES)));
    }
    return $state;
  }

  /**
   * The arguments clients may set on any of the types.
   *
   * @return string[]
   */
  public static function arguments(): array {
    $fields = [];
    foreach (static::FIELDS as $type_fields) {
      $fields += $type_fields;
    }
    return array_merge(['title', 'summary', 'body', 'state', 'log'], array_keys($fields));
  }

  /**
   * Copies title, summary, body and the type's own fields; text is stored
   * as plain text.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function apply(Artifact $artifact, array $values): void {
    $own = static::FIELDS[$artifact->bundle()] ?? [];
    $foreign = array_diff(array_keys(array_diff_key($values, array_flip(['title', 'summary', 'body', 'state', 'log']))), array_keys($own));
    if ($foreign) {
      throw new EngineeringWriteException(sprintf('A %s has no %s.', $artifact->bundle(), implode(', ', $foreign)));
    }
    foreach ($own as $argument => $field) {
      if (array_key_exists($argument, $values) && $artifact->hasField($field)) {
        $artifact->set($field, trim((string) $values[$argument]));
      }
    }
    if (array_key_exists('title', $values)) {
      $artifact->set('title', mb_substr(trim((string) $values['title']), 0, 255));
    }
    if (array_key_exists('summary', $values)) {
      $artifact->set('summary', trim((string) $values['summary']));
    }
    if (array_key_exists('body', $values)) {
      // Plain text: a client cannot inject markup into Studio pages.
      $artifact->set('body', ['value' => (string) $values['body'], 'format' => 'plain_text']);
    }
  }

  /**
   * Validates as the person, then saves.
   */
  protected function save(ContentEntityInterface $entity, AccountInterface $account, string $operation): void {
    $allowed = $entity->isNew()
      ? $this->entityTypeManager->getAccessControlHandler($entity->getEntityTypeId())->createAccess($entity->bundle(), $account)
      : $entity->access($operation, $account);
    if (!$allowed) {
      throw new EngineeringWriteException(sprintf('You may not %s this %s.', $operation, $entity->getEntityType()->getSingularLabel()));
    }
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    try {
      $violations = $entity->validate();
      if ($violations->count()) {
        $messages = [];
        // Name the arguments clients send, not Drupal's field names.
        $arguments = array_flip(static::FIELDS[$entity->bundle()] ?? []);
        foreach ($violations as $violation) {
          $field = strtok((string) $violation->getPropertyPath(), '.');
          $field = $arguments[$field] ?? $field;
          $messages[] = ($field ? "$field: " : '') . strip_tags((string) $violation->getMessage());
        }
        throw new EngineeringWriteException(implode(' ', array_unique($messages)));
      }
      $entity->save();
    }
    catch (EntityStorageException $e) {
      throw new EngineeringWriteException($e->getMessage(), 0, $e);
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * What a client reads back about a record.
   */
  protected function describe(ContentEntityInterface $entity, AccountInterface $account): array {
    $data = [
      'code' => $this->codes->codeOf($entity),
      'kind' => $entity instanceof Artifact ? $entity->bundle() : $entity->getEntityTypeId(),
      'title' => $entity->label(),
      'url' => $entity->toUrl()->setAbsolute()->toString(),
    ];
    if ($entity instanceof Artifact) {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('artifact');
      $latest = $storage->loadRevision($storage->getLatestRevisionId($entity->id()));
      $data += [
        'development' => $latest->get('development')->entity?->get('code')->value,
        'state' => $latest->get('moderation_state')->value,
        'revision' => (int) $latest->getRevisionId(),
        'title' => $latest->label(),
        'summary' => $latest->get('summary')->value,
        'body' => $latest->get('body')->value,
      ];
      foreach (static::FIELDS[$latest->bundle()] ?? [] as $argument => $field) {
        if ($latest->hasField($field)) {
          $data[$argument] = $latest->get($field)->value;
        }
      }
    }
    if ($entity instanceof ExternalReference) {
      $data += [
        'development' => $entity->getDevelopment()?->get('code')->value,
        'link' => $entity->getUrl(),
        'provider' => $entity->get('provider')->value,
        'external_kind' => $entity->get('kind')->value,
        'revision' => (int) $entity->getRevisionId(),
      ];
    }
    $data['traces'] = $this->traces($entity, $account);
    return $data;
  }

  /**
   * The trace links of a record the person may see, as sentences.
   */
  protected function traces(ContentEntityInterface $entity, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $query = $storage->getQuery()->accessCheck(TRUE)->addMetaData('account', $account);
    $source = $query->andConditionGroup()->condition('source_entity_type', $entity->getEntityTypeId())->condition('source_entity_id', $entity->id());
    $target = $query->andConditionGroup()->condition('target_entity_type', $entity->getEntityTypeId())->condition('target_entity_id', $entity->id());
    $ids = $query->condition($query->orConditionGroup()->condition($source)->condition($target))->sort('id')->execute();
    $out = [];
    foreach ($storage->loadMultiple($ids) as $link) {
      if (!$link->access('view', $account)) {
        continue;
      }
      $ends = [$link->getSourceEntity(), $link->getTargetEntity()];
      $out[] = [
        'trace_link' => (int) $link->id(),
        'source' => $ends[0] ? ($this->codes->codeOf($ends[0]) ?? $ends[0]->label()) : NULL,
        'type' => $link->get('link_type')->value,
        'target' => $ends[1] ? ($this->codes->codeOf($ends[1]) ?? $ends[1]->label()) : NULL,
        'rationale' => $link->get('rationale')->value,
      ];
    }
    return $out;
  }

  /**
   * The revision log of a write: which client made it, and why.
   */
  protected function log(string $client, string $message): string {
    return mb_substr(sprintf('Through MCP (%s): %s', $client, trim($message) ?: 'No reason given.'), 0, 1000);
  }

  /**
   * The record an earlier request with the same idempotency key created.
   *
   * Only the artifact's ID is remembered, never its content. A replay
   * reloads it and answers with what the person may see now; a record that
   * was deleted or is hidden from them is not described, and nothing is
   * created again.
   *
   * @throws \Drupal\divengine_mcp_write\EngineeringWriteException
   */
  protected function replay(string $key, string $request, AccountInterface $account): ?array {
    if ($key === '') {
      return NULL;
    }
    $stored = $this->keyValue->get('divengine_mcp_write.idempotency')->get($account->id() . ':' . $key);
    if (!$stored) {
      return NULL;
    }
    if (($stored['request'] ?? NULL) !== $request) {
      throw new EngineeringWriteException('This idempotency key was used for a different request.');
    }
    $artifact = isset($stored['id']) ? $this->entityTypeManager->getStorage('artifact')->load($stored['id']) : NULL;
    if (!$artifact || !$artifact->access('view', $account)) {
      throw new EngineeringWriteException('This idempotency key was already used, and what it created is not available to you. Use a new key to create a record.');
    }
    return $this->describe($artifact, $account) + ['replayed' => TRUE];
  }

  /**
   * Remembers which artifact a request with an idempotency key created.
   */
  protected function remember(string $key, string $request, Artifact $artifact, AccountInterface $account): void {
    if ($key !== '') {
      $this->keyValue->get('divengine_mcp_write.idempotency')->setWithExpire($account->id() . ':' . $key, ['request' => $request, 'id' => (int) $artifact->id()], static::IDEMPOTENCY_TTL);
    }
  }

  /**
   * Logs who wrote what through which client, never the content.
   */
  protected function audit(string $operation, ContentEntityInterface $entity, AccountInterface $account, string $client): void {
    $this->logger->notice('@operation @type @id by user @uid through MCP client @client.', [
      '@operation' => $operation,
      '@type' => $entity->getEntityTypeId(),
      '@id' => $entity->id(),
      '@uid' => $account->id(),
      '@client' => $client,
    ]);
  }

}
