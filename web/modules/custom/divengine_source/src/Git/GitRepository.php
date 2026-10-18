<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Git;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Read-only access to a local Git object database.
 *
 * - Git runs with an argument list, never through a shell, with a timeout.
 * - Only rev-parse, ls-tree, cat-file, grep on a commit and update-ref (in
 *   Studio's own refs/codeintel/snapshots/ namespace) are used: no clone,
 *   fetch, pull, checkout, reset, merge or rebase.
 * - Everything is read from commits, trees and blobs, never from the working
 *   tree, so the checked-out branch and uncommitted changes do not matter.
 * - Ref names are validated; object ids come from Git or from the index.
 */
class GitRepository {

  const PIN_NAMESPACE = 'refs/codeintel/snapshots/';

  const BINARY_SNIFF_BYTES = 8000;

  public function __construct(
    protected string $path,
    protected string $binary = 'git',
    protected int $timeout = 120,
  ) {}

  public static function validateRef(string $name): string {
    if ($name === '' || !preg_match('#^[A-Za-z0-9._/+-]+$#', $name) || str_starts_with($name, '-') || str_starts_with($name, '/')
      || str_ends_with($name, '/') || str_ends_with($name, '.') || str_ends_with($name, '.lock') || str_contains($name, '..') || str_contains($name, '//')) {
      throw new GitException(sprintf('Invalid ref name: %s', $name));
    }
    return $name;
  }

  public static function validateSha(string $sha): string {
    if (!preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $sha)) {
      throw new GitException('Invalid object id.');
    }
    return $sha;
  }

  /**
   * Git's own heuristic: a NUL byte in the first 8000 bytes means binary.
   */
  public static function isBinary(string $data): bool {
    return str_contains(substr($data, 0, static::BINARY_SNIFF_BYTES), "\0");
  }

  /**
   * The commit a ref points to in the local clone (annotated tags peeled).
   */
  public function resolveCommit(string $ref): string {
    static::validateRef($ref);
    $process = $this->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref . '^{commit}'], FALSE);
    $sha = trim($process->getOutput());
    if (!$process->isSuccessful() || !preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $sha)) {
      throw new GitException(sprintf('The ref %s does not resolve to a commit in the local repository.', $ref));
    }
    return $sha;
  }

  /**
   * Every path of a commit tree.
   *
   * @return array{0: array<int, array{path: string, mode: string, type: string, sha: string, size: int, entry: string}>, 1: int}
   *   The entries, and the number of paths skipped because their name is
   *   not valid UTF-8.
   */
  public function lsTree(string $commit): array {
    static::validateSha($commit);
    $output = $this->run(['ls-tree', '-r', '-l', '-z', '--full-tree', $commit])->getOutput();
    $entries = [];
    $undecodable = 0;
    foreach (explode("\0", $output) as $record) {
      if ($record === '') {
        continue;
      }
      [$meta, $path] = array_pad(explode("\t", $record, 2), 2, NULL);
      $parts = preg_split('/\s+/', trim((string) $meta));
      if ($path === NULL || count($parts) !== 4) {
        throw new GitException('Unexpected ls-tree output.');
      }
      if (!mb_check_encoding($path, 'UTF-8')) {
        $undecodable++;
        continue;
      }
      [$mode, $type, $sha, $size] = $parts;
      $entries[] = [
        'path' => $path,
        'mode' => $mode,
        'type' => $type,
        'sha' => $sha,
        'size' => $size === '-' ? 0 : (int) $size,
        'entry' => $type === 'commit' ? 'submodule' : ($mode === '120000' ? 'symlink' : 'blob'),
      ];
    }
    return [$entries, $undecodable];
  }

  /**
   * The content of a blob, from the object database, up to a size.
   *
   * @throws \Drupal\divengine_source\Git\BlobTooLargeException
   */
  public function readBlob(string $sha, int $max_bytes): string {
    static::validateSha($sha);
    if (trim($this->run(['cat-file', '-t', $sha])->getOutput()) !== 'blob') {
      throw new GitException('The object is not a blob.');
    }
    $size = (int) trim($this->run(['cat-file', '-s', $sha])->getOutput());
    if ($size > $max_bytes) {
      throw new BlobTooLargeException(sprintf('The file is %d bytes; the viewer limit is %d bytes.', $size, $max_bytes));
    }
    return $this->run(['cat-file', 'blob', $sha])->getOutput();
  }

  /**
   * Lines matching a pattern in a commit (fixed string, case-insensitive),
   * optionally below a path.
   *
   * @return array<int, array{path: string, line: int, text: string}>
   */
  public function grep(string $commit, string $pattern, string $path = '', int $limit = 200): array {
    static::validateSha($commit);
    if ($pattern === '' || mb_strlen($pattern) > 200) {
      throw new GitException('Search for 1 to 200 characters.');
    }
    $args = ['grep', '-n', '-I', '-i', '-F', '--no-color', '-e', $pattern, $commit];
    if ($path !== '') {
      $args[] = '--';
      $args[] = $path;
    }
    $process = $this->run($args, FALSE);
    if (!in_array($process->getExitCode(), [0, 1], TRUE)) {
      throw new GitException('git grep failed.');
    }
    $matches = [];
    foreach (explode("\n", $process->getOutput()) as $line) {
      // <commit>:<path>:<line>:<text>
      if (count($matches) >= $limit || !preg_match('/^[0-9a-f]+:(.+?):(\d+):(.*)$/', $line, $m)) {
        continue;
      }
      $matches[] = ['path' => $m[1], 'line' => (int) $m[2], 'text' => mb_substr($m[3], 0, 300)];
    }
    return $matches;
  }

  /**
   * Keeps an indexed commit reachable (refs/codeintel/snapshots/<sha>) after
   * a force-push or history rewrite. Never moves an existing pin.
   */
  public function pinCommit(string $commit): bool {
    static::validateSha($commit);
    $ref = static::PIN_NAMESPACE . $commit;
    $existing = $this->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref], FALSE);
    if ($existing->isSuccessful()) {
      if (trim($existing->getOutput()) !== $commit) {
        throw new GitException(sprintf('The pin %s points to another commit.', $ref));
      }
      return FALSE;
    }
    // The zero old value makes update-ref create only.
    $this->run(['update-ref', '-m', 'divengine snapshot pin', $ref, $commit, str_repeat('0', strlen($commit))]);
    return TRUE;
  }

  protected function run(array $args, bool $check = TRUE): Process {
    $process = new Process(
      array_merge([$this->binary, '-C', $this->path, '-c', 'core.quotepath=off'], $args),
      NULL,
      ['GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0', 'LC_ALL' => 'C'],
      NULL,
      $this->timeout,
    );
    try {
      $process->run();
    }
    catch (ProcessTimedOutException) {
      throw new GitException(sprintf('git %s timed out after %ds.', $args[0], $this->timeout));
    }
    if ($check && !$process->isSuccessful()) {
      $lines = array_filter(explode("\n", trim($process->getErrorOutput())));
      throw new GitException(sprintf('git %s failed: %s', $args[0], $lines ? end($lines) : 'exit ' . $process->getExitCode()));
    }
    return $process;
  }

}
