<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

/**
 * Test URLs: complete http:// or https:// addresses or nothing.
 */
final class TestUrl {

  /**
   * Trims a URL; blank becomes ''.
   */
  public static function normalize(?string $url): string {
    return trim((string) $url);
  }

  /**
   * Whether a non-empty URL is a complete http or https address.
   *
   * It needs a host and may not contain whitespace or control characters.
   */
  public static function isValid(string $url): bool {
    if ($url === '') {
      return TRUE;
    }
    if (preg_match('/[\s\x00-\x1F]/u', $url)) {
      return FALSE;
    }
    $parts = parse_url($url);
    return $parts !== FALSE
      && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], TRUE)
      && ($parts['host'] ?? '') !== '';
  }

}
