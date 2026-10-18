<?php

declare(strict_types=1);

namespace Drupal\divengine_source;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_source\Entity\SourceRef;
use Drupal\divengine_source\Entity\SourceSnapshot;
use Drupal\divengine_source\Git\GitException;
use Psr\Log\LoggerInterface;

/**
 * Indexes refs and answers questions about the indexed code.
 *
 * Indexing resolves a ref to a commit, reads the commit's tree from the
 * object database and brings the index in line: file identities and
 * revisions are deduplicated across refs, and each file's state in the ref
 * says whether it is present and which revision is current. Nothing of the
 * code itself is stored.
 */
class SourceIndex {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SourceGit $git,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Indexes a ref at the commit it points to now.
   *
   * @return array{0: \Drupal\divengine_source\Entity\SourceSnapshot, 1: string}
   *   The snapshot and the outcome: indexed, unchanged, restored or failed.
   *
   * @throws \Drupal\divengine_source\Git\GitException
   *   When the repository is not allowed or the ref does not resolve.
   */
  public function index(SourceRef $ref): array {
    $repository = $ref->getRepository();
    $git = $this->git->repository($repository);
    $commit = $git->resolveCommit((string) $ref->get('name')->value);
    $snapshots = $this->entityTypeManager->getStorage('source_snapshot');
    $existing = $snapshots->loadByProperties(['ref' => $ref->id(), 'commit_sha' => $commit]);
    /** @var \Drupal\divengine_source\Entity\SourceSnapshot|null $snapshot */
    $snapshot = reset($existing) ?: NULL;
    $restoring = FALSE;
    if ($snapshot && $snapshot->isReady()) {
      if ((string) $ref->get('last_snapshot')->target_id === (string) $snapshot->id()) {
        return [$snapshot, 'unchanged'];
      }
      // The ref points again to a commit indexed before: re-apply its tree.
      $restoring = TRUE;
    }
    else {
      $values = ['state' => 'draft', 'error' => NULL, 'indexed' => $this->time->getRequestTime()];
      if (!$snapshot) {
        $snapshot = $snapshots->create($values + [
          'ref' => $ref->id(),
          'repository' => $repository->id(),
          'development' => $repository->get('development')->target_id,
          'commit_sha' => $commit,
        ]);
      }
      foreach ($values as $field => $value) {
        $snapshot->set($field, $value);
      }
      $snapshot->save();
    }

    $transaction = $this->database->startTransaction();
    try {
      [$entries, $undecodable] = $git->lsTree($commit);
      $counters = $this->applyTree($ref, $snapshot, $commit, $entries);
      $git->pinCommit($commit);
      if (!$restoring) {
        foreach ($counters as $field => $value) {
          $snapshot->set($field, $value);
        }
        $snapshot->set('skipped_count', $undecodable)->set('state', 'ready')->save();
      }
      $ref->set('last_snapshot', $snapshot->id())->set('last_indexed', $this->time->getRequestTime())->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      unset($transaction);
      $message = $e instanceof GitException ? $e->getMessage() : sprintf('Unexpected %s while indexing; see the log.', get_class($e));
      if (!$e instanceof GitException) {
        $this->logger->error('Indexing @ref at @commit failed: @message', ['@ref' => $ref->label(), '@commit' => $commit, '@message' => $e->getMessage()]);
      }
      if ($restoring) {
        throw new GitException($message, 0, $e);
      }
      $snapshot = $snapshots->loadUnchanged($snapshot->id()) ?? $snapshot;
      $snapshot->set('state', 'failed')->set('error', $message)->save();
      return [$snapshot, 'failed'];
    }
    unset($transaction);
    return [$snapshot, $restoring ? 'restored' : 'indexed'];
  }

  /**
   * Brings the index of a ref in line with a commit tree.
   *
   * @return array<string, int>
   */
  protected function applyTree(SourceRef $ref, SourceSnapshot $snapshot, string $commit, array $entries): array {
    $repository_id = (int) $ref->get('repository')->target_id;
    $ref_id = (int) $ref->id();
    $snapshot_id = (int) $snapshot->id();

    // File identities.
    $files = $this->database->select('divengine_source_file', 'f')->fields('f', ['path', 'fid'])->condition('f.repository_id', $repository_id)->execute()->fetchAllKeyed();
    $rows = [];
    foreach ($entries as $entry) {
      if (!isset($files[$entry['path']])) {
        $slash = strrpos($entry['path'], '/');
        $filename = $slash === FALSE ? $entry['path'] : substr($entry['path'], $slash + 1);
        $dot = strrpos($filename, '.');
        $rows[] = [
          'repository_id' => $repository_id,
          'path' => $entry['path'],
          'directory' => $slash === FALSE ? '' : substr($entry['path'], 0, $slash),
          'filename' => mb_substr($filename, 0, 255),
          'extension' => $dot ? mb_strtolower(mb_substr(substr($filename, $dot + 1), 0, 32)) : '',
        ];
        $files[$entry['path']] = NULL;
      }
    }
    if ($rows) {
      $this->insert('divengine_source_file', ['repository_id', 'path', 'directory', 'filename', 'extension'], $rows);
      $files = $this->database->select('divengine_source_file', 'f')->fields('f', ['path', 'fid'])->condition('f.repository_id', $repository_id)->execute()->fetchAllKeyed();
    }

    // Revisions, one per (file, blob).
    $tree_fids = array_map(fn ($entry) => (int) $files[$entry['path']], $entries);
    $revisions = [];
    foreach (array_chunk($tree_fids, 5000) as $chunk) {
      $query = $this->database->select('divengine_source_revision', 'r')->fields('r', ['fid', 'blob_sha', 'rid'])->condition('r.fid', $chunk, 'IN');
      foreach ($query->execute() as $row) {
        $revisions[$row->fid . ':' . $row->blob_sha] = (int) $row->rid;
      }
    }
    $pending = [];
    foreach ($entries as $entry) {
      $key = $files[$entry['path']] . ':' . $entry['sha'];
      if (!isset($revisions[$key]) && !isset($pending[$key])) {
        $pending[$key] = [(int) $files[$entry['path']], $entry['sha'], $entry['mode'], $entry['entry'], $entry['size'], $snapshot_id, $commit];
      }
    }
    if ($pending) {
      $this->insert('divengine_source_revision', ['fid', 'blob_sha', 'mode', 'entry_type', 'size', 'first_snapshot_id', 'first_commit'], array_values($pending));
      foreach (array_chunk($tree_fids, 5000) as $chunk) {
        $query = $this->database->select('divengine_source_revision', 'r')->fields('r', ['fid', 'blob_sha', 'rid'])->condition('r.fid', $chunk, 'IN');
        foreach ($query->execute() as $row) {
          $revisions[$row->fid . ':' . $row->blob_sha] = (int) $row->rid;
        }
      }
    }

    // States of the ref.
    $states = [];
    foreach ($this->database->select('divengine_source_state', 's')->fields('s', ['fid', 'present', 'rid'])->condition('s.ref_id', $ref_id)->execute() as $row) {
      $states[(int) $row->fid] = $row;
    }
    $counters = ['file_count' => count($entries), 'added_count' => 0, 'changed_count' => 0, 'reactivated_count' => 0, 'missing_count' => 0];
    $new_states = [];
    $changed = [];
    $in_tree = [];
    foreach ($entries as $entry) {
      $fid = (int) $files[$entry['path']];
      $rid = $revisions[$fid . ':' . $entry['sha']];
      $in_tree[$fid] = TRUE;
      $state = $states[$fid] ?? NULL;
      if (!$state) {
        $counters['added_count']++;
        $new_states[] = [$fid, $ref_id, 1, $rid, $snapshot_id, $snapshot_id, $commit, $commit];
      }
      elseif (!$state->present) {
        $counters['reactivated_count']++;
        $changed[$fid] = $rid;
      }
      elseif ((int) $state->rid !== $rid) {
        $counters['changed_count']++;
        $changed[$fid] = $rid;
      }
    }
    if ($new_states) {
      $this->insert('divengine_source_state', ['fid', 'ref_id', 'present', 'rid', 'first_snapshot_id', 'last_snapshot_id', 'first_commit', 'last_commit'], $new_states);
    }
    foreach ($changed as $fid => $rid) {
      $this->database->update('divengine_source_state')->fields(['present' => 1, 'rid' => $rid])->condition('fid', $fid)->condition('ref_id', $ref_id)->execute();
    }
    // Everything in the tree was seen in this snapshot.
    $this->database->update('divengine_source_state')
      ->fields(['last_snapshot_id' => $snapshot_id, 'last_commit' => $commit])
      ->condition('ref_id', $ref_id)
      ->condition('present', 1)
      ->execute();
    // Paths gone from the tree stay known; only their presence changes.
    $gone = array_keys(array_filter($states, fn ($state, $fid) => $state->present && !isset($in_tree[$fid]), ARRAY_FILTER_USE_BOTH));
    $counters['missing_count'] = count($gone);
    foreach (array_chunk($gone, 5000) as $chunk) {
      $this->database->update('divengine_source_state')->fields(['present' => 0])->condition('ref_id', $ref_id)->condition('fid', $chunk, 'IN')->execute();
    }
    return $counters;
  }

  /**
   * Inserts rows in batches (databases limit the parameters of a query).
   */
  protected function insert(string $table, array $fields, array $rows): void {
    foreach (array_chunk($rows, 1000) as $chunk) {
      $insert = $this->database->insert($table)->fields($fields);
      foreach ($chunk as $row) {
        $insert->values(array_values($row));
      }
      $insert->execute();
    }
  }

  /**
   * The entries of a directory of an indexed ref: folders first, then
   * files.
   *
   * @return array{folders: array<string, int>, files: array<int, object>}
   *   Folder name => number of files below it; files with fid, path,
   *   filename, size, blob_sha, entry_type.
   */
  public function directory(SourceRef $ref, string $directory): array {
    $directory = trim($directory, '/');
    $query = $this->database->select('divengine_source_state', 's');
    $query->join('divengine_source_file', 'f', 'f.fid = s.fid');
    $query->join('divengine_source_revision', 'r', 'r.rid = s.rid');
    $query->fields('f', ['fid', 'path', 'filename', 'directory'])->fields('r', ['size', 'blob_sha', 'entry_type'])
      ->condition('s.ref_id', $ref->id())
      ->condition('s.present', 1);
    if ($directory !== '') {
      $query->condition($query->orConditionGroup()
        ->condition('f.directory', $directory)
        ->condition('f.directory', $this->database->escapeLike($directory) . '/%', 'LIKE'));
    }
    $folders = [];
    $files = [];
    $prefix = $directory === '' ? '' : $directory . '/';
    foreach ($query->execute() as $row) {
      if ($row->directory === $directory) {
        $files[] = $row;
        continue;
      }
      $rest = substr($row->path, strlen($prefix));
      $folder = strstr($rest, '/', TRUE);
      if ($folder !== FALSE) {
        $folders[$folder] = ($folders[$folder] ?? 0) + 1;
      }
    }
    ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    usort($files, fn ($a, $b) => strnatcasecmp($a->filename, $b->filename));
    return ['folders' => $folders, 'files' => $files];
  }

  /**
   * A present file of an indexed ref, with its current revision.
   */
  public function file(SourceRef $ref, string $path): ?object {
    $query = $this->database->select('divengine_source_state', 's');
    $query->join('divengine_source_file', 'f', 'f.fid = s.fid');
    $query->join('divengine_source_revision', 'r', 'r.rid = s.rid');
    $query->fields('f', ['fid', 'path', 'filename', 'extension', 'directory'])->fields('r', ['rid', 'size', 'blob_sha', 'entry_type', 'mode', 'first_commit'])
      ->fields('s', ['first_commit', 'last_commit'])
      ->condition('s.ref_id', $ref->id())
      ->condition('s.present', 1)
      ->condition('f.path', trim($path, '/'));
    return $query->execute()->fetchObject() ?: NULL;
  }

  /**
   * Present files whose path contains a text.
   *
   * @return object[]
   */
  public function findFiles(SourceRef $ref, string $text, int $limit = 100): array {
    $query = $this->database->select('divengine_source_state', 's');
    $query->join('divengine_source_file', 'f', 'f.fid = s.fid');
    $query->join('divengine_source_revision', 'r', 'r.rid = s.rid');
    $query->fields('f', ['path', 'filename'])->fields('r', ['size'])
      ->condition('s.ref_id', $ref->id())
      ->condition('s.present', 1)
      ->condition('f.path', '%' . $this->database->escapeLike($text) . '%', 'ILIKE')
      ->orderBy('f.path')
      ->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * How many files are present in a ref.
   */
  public function count(SourceRef $ref): int {
    return (int) $this->database->select('divengine_source_state', 's')->condition('s.ref_id', $ref->id())->condition('s.present', 1)->countQuery()->execute()->fetchField();
  }

  /**
   * The content of an indexed file, read from Git now.
   *
   * @throws \Drupal\divengine_source\Git\GitException
   */
  public function read(SourceRef $ref, object $file): string {
    return $this->git->repository($ref->getRepository())->readBlob($file->blob_sha, $this->git->maxBytes());
  }

  /**
   * Lines of the ref's indexed commit that contain a text.
   *
   * @throws \Drupal\divengine_source\Git\GitException
   */
  public function grep(SourceRef $ref, string $text, string $path = '', int $limit = 200): array {
    $snapshot = $ref->getLastSnapshot();
    if (!$snapshot) {
      throw new GitException(sprintf('%s has not been indexed yet.', $ref->label()));
    }
    return $this->git->repository($ref->getRepository())->grep($snapshot->getCommit(), $text, trim($path, '/'), $limit);
  }

  /**
   * Forgets the index of deleted refs.
   */
  public function forgetRefs(array $ref_ids): void {
    if ($ref_ids) {
      $this->database->delete('divengine_source_state')->condition('ref_id', $ref_ids, 'IN')->execute();
    }
  }

  /**
   * Forgets the index of deleted repositories.
   */
  public function forgetRepositories(array $repository_ids): void {
    if (!$repository_ids) {
      return;
    }
    $fids = $this->database->select('divengine_source_file', 'f')->fields('f', ['fid'])->condition('f.repository_id', $repository_ids, 'IN')->execute()->fetchCol();
    foreach (array_chunk($fids, 5000) as $chunk) {
      $this->database->delete('divengine_source_state')->condition('fid', $chunk, 'IN')->execute();
      $this->database->delete('divengine_source_revision')->condition('fid', $chunk, 'IN')->execute();
    }
    $this->database->delete('divengine_source_file')->condition('repository_id', $repository_ids, 'IN')->execute();
  }

}
