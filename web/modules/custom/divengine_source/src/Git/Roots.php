<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Git;

/**
 * The allowed repository roots: the filesystem boundary of Code Source.
 *
 * A repository may be registered, indexed or read only when its real path
 * (symbolic links and ".." resolved) is one of the roots or lies below one
 * of them. No roots allow nothing (fail closed). The same check runs when a
 * repository is saved and on every Git access.
 */
final class Roots {

  /**
   * The roots from the setting: one absolute path per line; blank lines,
   * "#" comments and relative paths are ignored.
   *
   * @return string[]
   */
  public static function parse(?string $text): array {
    $roots = [];
    foreach (preg_split('/\R/', (string) $text) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#') || !str_starts_with($line, '/')) {
        continue;
      }
      $roots[$line] = $line;
    }
    return array_values($roots);
  }

  /**
   * The real path of a directory inside the roots.
   *
   * Containment is decided on whole path components, so /opt/repos-evil is
   * not inside /opt/repos.
   *
   * @throws \Drupal\divengine_source\Git\GitException
   */
  public static function resolve(?string $path, array $roots): string {
    if (!$path || !str_starts_with($path, '/')) {
      throw new GitException('The repository path must be an absolute path.');
    }
    if (!$roots) {
      throw new GitException('No allowed repository roots are configured (Configuration › Code Source).');
    }
    $real = realpath($path);
    if ($real === FALSE) {
      throw new GitException(sprintf('The repository path does not exist or cannot be reached by the web server: %s.', $path));
    }
    foreach ($roots as $root) {
      $real_root = realpath($root);
      if ($real_root === FALSE) {
        continue;
      }
      if ($real === $real_root || str_starts_with($real, rtrim($real_root, '/') . '/')) {
        if (!is_dir($real)) {
          throw new GitException(sprintf('The repository path is not a directory: %s.', $real));
        }
        if (!is_readable($real) || !is_executable($real)) {
          throw new GitException(sprintf('The web server cannot read %s: permission denied.', $real));
        }
        return $real;
      }
    }
    throw new GitException('The repository path is outside the allowed repository roots.');
  }

}
