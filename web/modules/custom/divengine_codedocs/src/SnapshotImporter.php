<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_codedocs\Contract\AdapterManager;
use Drupal\divengine_codedocs\Contract\Canonical;
use Drupal\divengine_codedocs\Contract\ContractException;
use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Drupal\divengine_codedocs\Entity\CodeDocSnapshot;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Chunked uploads: author-owned, transactional, and immutable once ready. */
class SnapshotImporter {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entities,
    protected AdapterManager $adapters,
    protected CodeDocIndex $index,
    protected AccountInterface $account,
    protected LockBackendInterface $lock,
  ) {}

  public function setFor(string $identifier): CodeDocSet {
    $sets = $this->entities->getStorage('codedoc_set')->loadByProperties(['identifier' => $identifier]);
    return reset($sets) ?: throw new ContractException('Register a documentation set in its Development first: ' . $identifier);
  }

  public function mayPublish(CodeDocSet $set): bool {
    $development = $set->getDevelopment();
    if (!$development) {
      return FALSE;
    }
    if ($this->account->hasPermission('manage code docs') || $this->account->hasPermission('administer code docs')) {
      return $development->access('view', $this->account);
    }
    if (!$this->account->hasPermission('publish code docs')) {
      return FALSE;
    }
    $members = array_merge(array_column($development->get('members')->getValue(), 'target_id'), [$development->get('responsible')->target_id, $development->getOwnerId()]);
    return in_array((string) $this->account->id(), array_map('strval', $members), TRUE);
  }

  public function begin(array $header, int $chunks, string $format = 'auto'): CodeDocSnapshot {
    if ($chunks < 1 || $chunks > 100000) {
      throw new ContractException('chunks must be between 1 and 100000.');
    }
    $adapter = $this->adapters->resolve($header, $format);
    $meta = $adapter->meta($header);
    $set = $this->setFor((string) ($meta['project'] ?? ''));
    if (!$this->mayPublish($set)) {
      throw new AccessDeniedHttpException('You cannot publish into this Development.');
    }
    $language = (string) ($meta['language'] ?? '');
    if ($language === '' || ($set->get('language')->value && strtolower($set->get('language')->value) !== strtolower($language))) {
      throw new ContractException('The payload language must match the documentation set.');
    }
    foreach ($adapter->chunkKeys() as $key) {
      unset($header[$key]);
    }
    $captured = $meta['captured_at'] ?? time();
    if (!is_int($captured)) {
      try {
        $captured = (new \DateTimeImmutable((string) $captured, new \DateTimeZone('UTC')))->getTimestamp();
      }
      catch (\Exception) {
        throw new ContractException('Invalid captured_at timestamp.');
      }
    }
    $header['_upload_chunks'] = $chunks;
    $snapshot = $this->entities->getStorage('codedoc_snapshot')->create([
      'snapshot_key' => 'upload:' . bin2hex(random_bytes(16)), 'uid' => $this->account->id(),
      'development' => $set->get('development')->target_id, 'doc_set' => $set->id(), 'state' => 'uploading',
      'format' => $adapter->id(), 'header' => Canonical::json($header), 'language' => $language,
      'producer' => $meta['producer'] ?? '', 'commit_sha' => $meta['commit'] ?? '', 'branch' => $meta['branch'] ?? '',
      'captured_at' => $captured, 'source_hash' => $header['content_hash'] ?? '',
    ]);
    // The importer sets these references itself, after mayPublish(): a
    // publisher account may not be able to view what it publishes into.
    $violations = $snapshot->validate()->filterByFields(['uid', 'development', 'doc_set']);
    if ($violations->count()) {
      throw new ContractException(strip_tags((string) $violations->get(0)->getMessage()));
    }
    $snapshot->save();
    return $snapshot;
  }

  public function append(int $id, int $sequence, array $chunk): array {
    return $this->locked('snapshot:' . $id, function () use ($id, $sequence, $chunk) {
      $snapshot = $this->upload($id);
      $header = json_decode($snapshot->get('header')->value, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($sequence < 0 || $sequence >= $header['_upload_chunks']) {
        throw new ContractException('sequence is outside the declared upload range.');
      }
      $adapter = $this->adapters->resolve($header, $snapshot->get('format')->value);
      if (!$chunk || array_diff(array_keys($chunk), $adapter->chunkKeys())) {
        throw new ContractException('Chunk keys must be: ' . implode(', ', $adapter->chunkKeys()));
      }
      foreach ($chunk as $key => $items) {
        if (!is_array($items) || !array_is_list($items) || array_filter($items, fn ($item) => !is_array($item))) {
          throw new ContractException($key . ' must be an array of objects.');
        }
      }
      $digest = Canonical::hash($chunk);
      $old = $this->database->select('divengine_codedoc_batch', 'b')->fields('b', ['digest'])->condition('sid', $id)->condition('sequence', $sequence)->execute()->fetchField();
      if ($old) {
        if (!hash_equals($old, $digest)) {
          throw new ContractException('This sequence was already uploaded with different content.');
        }
        return ['status' => 'idempotent', 'sequence' => $sequence];
      }
      $units = Canonical::units($adapter->units($chunk, $header));
      $transaction = $this->database->startTransaction();
      try {
        $this->index->write($id, $units);
        $this->database->insert('divengine_codedoc_batch')->fields(['sid' => $id, 'sequence' => $sequence, 'digest' => $digest])->execute();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      return ['status' => 'accepted', 'sequence' => $sequence, 'units' => count($units)];
    });
  }

  public function finish(int $id, bool $force = FALSE): array {
    return $this->locked('snapshot:' . $id, function () use ($id, $force) {
      $snapshot = $this->upload($id, TRUE);
      if ($snapshot->isReady()) {
        return ['status' => 'idempotent', 'snapshot' => $snapshot];
      }
      return $this->locked('set:' . $snapshot->get('doc_set')->target_id, function () use ($snapshot, $id, $force) {
        $header = json_decode($snapshot->get('header')->value, TRUE, 512, JSON_THROW_ON_ERROR);
        $count = (int) $this->database->select('divengine_codedoc_batch', 'b')->condition('sid', $id)->countQuery()->execute()->fetchField();
        if ($count !== $header['_upload_chunks']) {
          throw new ContractException(sprintf('Upload incomplete: received %d of %d chunks.', $count, $header['_upload_chunks']));
        }
        $transaction = $this->database->startTransaction();
        try {
          $counts = $this->index->finalize($id);
          $hash = Canonical::hash($this->index->fingerprints($id));
          $set = $snapshot->getDocSet();
          $revision = (string) $snapshot->get('commit_sha')->value;
          $key = $set->getIdentifier() . '@' . substr(hash('sha256', $revision ?: $hash), 0, 32);
          $existing = $this->entities->getStorage('codedoc_snapshot')->loadByProperties(['snapshot_key' => $key, 'state' => 'ready']);
          $existing = reset($existing);
          if ($existing && $existing->get('content_hash')->value !== $hash) {
            throw new ContractException('This revision already has different documentation. Publish a distinct revision.');
          }
          $latest = $set->getLatestSnapshot();
          $same = $existing ?: (!$force && $latest?->get('content_hash')->value === $hash ? $latest : NULL);
          if ($same) {
            $snapshot->delete();
            return ['status' => $existing ? 'idempotent' : 'unchanged', 'snapshot' => $same];
          }
          foreach ($counts as $field => $value) {
            $snapshot->set($field, $value);
          }
          $snapshot->set('snapshot_key', $key)->set('content_hash', $hash)->set('state', 'ready')->save();
          if (!$set->get('language')->value) {
            $set->set('language', $snapshot->get('language')->value)->save();
          }
        }
        catch (\Throwable $e) {
          $transaction->rollBack();
          $this->entities->getStorage('codedoc_snapshot')->resetCache([$id]);
          throw $e;
        }
        return ['status' => 'imported', 'snapshot' => $snapshot];
      });
    });
  }

  public function discard(int $id): void {
    $this->locked('snapshot:' . $id, fn () => $this->upload($id)->delete());
  }

  public function import(array $document, string $format = 'auto', bool $force = FALSE): array {
    $adapter = $this->adapters->resolve($document, $format);
    $chunk = array_intersect_key($document, array_flip($adapter->chunkKeys()));
    $snapshot = $this->begin($document, 1, $adapter->id());
    try {
      $this->append((int) $snapshot->id(), 0, $chunk);
      return $this->finish((int) $snapshot->id(), $force);
    }
    catch (\Throwable $e) {
      $this->discard((int) $snapshot->id());
      throw $e;
    }
  }

  protected function upload(int $id, bool $allow_ready = FALSE): CodeDocSnapshot {
    $storage = $this->entities->getStorage('codedoc_snapshot');
    $storage->resetCache([$id]);
    $snapshot = $storage->load($id);
    if (!$snapshot instanceof CodeDocSnapshot || !$snapshot->getDocSet() || !$this->mayPublish($snapshot->getDocSet()) || ((string) $snapshot->get('uid')->target_id !== (string) $this->account->id() && !$this->account->hasPermission('administer code docs'))) {
      throw new AccessDeniedHttpException('This upload is not yours or is no longer accessible.');
    }
    if ($snapshot->isReady() && !$allow_ready) {
      throw new ContractException('Ready snapshots are immutable.');
    }
    return $snapshot;
  }

  protected function locked(string $key, callable $work): mixed {
    $key = 'codedocs:' . $key;
    if (!$this->lock->acquire($key, 300)) {
      throw new ContractException('Another request is updating this upload. Retry shortly.');
    }
    try {
      return $work();
    }
    finally {
      $this->lock->release($key);
    }
  }

}
