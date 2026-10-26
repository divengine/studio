<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_process\DevelopmentReferences;
use Drupal\divengine_traceability\Entity\TraceLinkInterface;

/**
 * Registers external work in a Development and links it to records.
 *
 * Writers (forms, the MCP tools) go through here so that a link to the same
 * work is registered once per Development and links are validated like any
 * other trace link.
 */
class ExternalReferences {

  public function __construct(
    protected ExternalProviderManager $providers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DevelopmentReferences $developmentReferences,
    protected LockBackendInterface $lock,
    protected Connection $database,
  ) {}

  /**
   * What a link points to.
   *
   * @throws \Drupal\divengine_external\InvalidExternalUrlException
   */
  public function identify(string $url): ExternalIdentity {
    $parsed = ExternalUrl::parse($url);
    return $this->providers->forHost($parsed->host)->identify($parsed);
  }

  /**
   * The reference to some work in a Development, whoever may see it.
   */
  public function find(int|string $development_id, ExternalIdentity|array $identity): ?ExternalReference {
    $fields = $identity instanceof ExternalIdentity ? $identity->fields() : $identity;
    $query = $this->entityTypeManager->getStorage('external_reference')->getQuery()
      ->accessCheck(FALSE)
      ->condition('development', $development_id);
    foreach ($fields as $field => $value) {
      $query->condition($field, $value);
    }
    $ids = $query->range(0, 1)->execute();
    return $ids ? ExternalReference::load(reset($ids)) : NULL;
  }

  /**
   * Refuses to store a second reference to the same work in a Development.
   *
   * Writers of the same work are serialized until the first one's
   * transaction ends, so the second one sees it.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function assertUnique(ExternalReference $reference): void {
    $development = (string) $reference->get('development')->target_id;
    $lock = 'divengine_external:' . hash('sha256', $development . '|' . implode('|', $reference->identityValues()));
    $deadline = time() + 30;
    while (!$this->lock->acquire($lock)) {
      if (time() >= $deadline) {
        throw new EntityStorageException('Another request is registering the same external work. Try again.');
      }
      $this->lock->wait($lock, 5);
    }
    if ($this->database->inTransaction()) {
      $this->database->transactionManager()->addPostTransactionCallback(fn () => $this->lock->release($lock));
    }
    $existing = $development === '' ? NULL : $this->find($development, $reference->identityValues());
    if (!$this->database->inTransaction()) {
      $this->lock->release($lock);
    }
    if ($existing && (string) $existing->id() !== (string) $reference->id()) {
      throw new EntityStorageException('This external work is already referenced in the Development.');
    }
  }

  /**
   * The reference to the work a link points to in a Development: the one
   * already registered, or a new one.
   *
   * @return array{0: \Drupal\divengine_external\Entity\ExternalReference, 1: bool}
   *   The reference, and whether it was created.
   *
   * @throws \Drupal\divengine_external\InvalidExternalUrlException
   * @throws \Drupal\divengine_external\ExternalReferenceException
   *   When the account may not register it.
   */
  public function resolve(int|string $development_id, string $url, AccountInterface $account, string $label = ''): array {
    // A Development the account does not see answers like a missing one,
    // without its name.
    $development = $this->entityTypeManager->getStorage('development')->load($development_id);
    if (!$development || !$development->access('view', $account)) {
      throw new ExternalReferenceException('No Development with that identifier is visible to you.');
    }
    $identity = $this->identify($url);
    $existing = $this->find($development_id, $identity);
    if ($existing) {
      if (!$existing->access('view', $account)) {
        throw new ExternalReferenceException('You cannot reference work in this Development.');
      }
      return [$existing, FALSE];
    }
    if (!$this->entityTypeManager->getAccessControlHandler('external_reference')->createAccess(NULL, $account)) {
      throw new ExternalReferenceException('You may not register external references.');
    }
    $reference = ExternalReference::create(['development' => $development_id, 'label' => $label, 'uid' => $account->id()]);
    $reference->setIdentity($identity);
    $this->assertValid($reference, $account);
    $reference->save();
    return [$reference, TRUE];
  }

  /**
   * Links a record and a reference with a typed trace link.
   *
   * An identical link that already exists is returned instead.
   *
   * @param bool $from_reference
   *   TRUE when the external work is the source ("PR implements REQ-1"),
   *   FALSE when the record is ("REQ-1 references issue #4").
   *
   * @return array{0: \Drupal\divengine_traceability\Entity\TraceLinkInterface, 1: bool}
   *   The trace link, and whether it was created.
   *
   * @throws \Drupal\divengine_external\ExternalReferenceException
   */
  public function link(ContentEntityInterface $record, ExternalReference $reference, string $link_type, bool $from_reference, AccountInterface $account, string $rationale = ''): array {
    [$source, $target] = $from_reference ? [$reference, $record] : [$record, $reference];
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('link_type', $link_type)
      ->condition('source_entity_type', $source->getEntityTypeId())
      ->condition('source_entity_id', $source->id())
      ->condition('target_entity_type', $target->getEntityTypeId())
      ->condition('target_entity_id', $target->id())
      ->range(0, 1)
      ->execute();
    if ($ids) {
      $link = $storage->load(reset($ids));
      if (!$link->access('view', $account)) {
        throw new ExternalReferenceException('You cannot see this trace link.');
      }
      return [$link, FALSE];
    }
    if (!$this->entityTypeManager->getAccessControlHandler('trace_link')->createAccess(NULL, $account)) {
      throw new ExternalReferenceException('You may not create trace links.');
    }
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkInterface $link */
    $link = $storage->create([
      'link_type' => $link_type,
      'source_entity_type' => $source->getEntityTypeId(),
      'source_entity_id' => $source->id(),
      'target_entity_type' => $target->getEntityTypeId(),
      'target_entity_id' => $target->id(),
      'rationale' => $rationale,
      'uid' => $account->id(),
    ]);
    $this->assertValid($link, $account);
    try {
      $link->save();
    }
    catch (EntityStorageException $e) {
      throw new ExternalReferenceException($e->getMessage(), 0, $e);
    }
    return [$link, TRUE];
  }

  /**
   * The Development of a record, if it belongs to one.
   */
  public function developmentOf(ContentEntityInterface $record): ?string {
    return $this->developmentReferences->developmentOf($record);
  }

  /**
   * How many trace links have the reference as an end, visible or not.
   */
  public function traceCount(ExternalReference $reference): int {
    $query = $this->entityTypeManager->getStorage('trace_link')->getQuery()->accessCheck(FALSE);
    $source = $query->andConditionGroup()
      ->condition('source_entity_type', 'external_reference')
      ->condition('source_entity_id', $reference->id());
    $target = $query->andConditionGroup()
      ->condition('target_entity_type', 'external_reference')
      ->condition('target_entity_id', $reference->id());
    return (int) $query->condition($query->orConditionGroup()->condition($source)->condition($target))->count()->execute();
  }

  /**
   * Validates an entity as the account that writes it.
   *
   * @throws \Drupal\divengine_external\ExternalReferenceException
   */
  protected function assertValid(ContentEntityInterface $entity, AccountInterface $account): void {
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    try {
      $violations = $entity->validate();
    }
    finally {
      $switcher->switchBack();
    }
    if ($violations->count()) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = strip_tags((string) $violation->getMessage());
      }
      throw new ExternalReferenceException(implode(' ', array_unique($messages)));
    }
  }

}
