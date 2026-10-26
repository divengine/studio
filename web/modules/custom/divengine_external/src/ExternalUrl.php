<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

/**
 * A link entered for an external reference, checked and normalized.
 *
 * Only absolute http and https links to a host name are accepted, without
 * credentials. The fragment is dropped: it points inside a page, not to
 * other work. Nothing here contacts the host.
 */
final class ExternalUrl {

  /**
   * The longest link accepted.
   */
  const MAX_LENGTH = 2048;

  /**
   * @param string $scheme
   *   "http" or "https".
   * @param string $host
   *   The host, lowercase, without a trailing dot.
   * @param int|null $port
   *   The port when it is not the scheme's default.
   * @param string[] $segments
   *   The path segments, decoded, without empty ones.
   * @param string $query
   *   The query string as written, without "?".
   */
  private function __construct(
    public readonly string $scheme,
    public readonly string $host,
    public readonly ?int $port,
    public readonly array $segments,
    public readonly string $query,
  ) {}

  /**
   * Checks and normalizes a link.
   *
   * @throws \Drupal\divengine_external\InvalidExternalUrlException
   */
  public static function parse(string $url): self {
    $url = trim($url);
    if ($url === '') {
      throw new InvalidExternalUrlException('Enter the link to the external work.');
    }
    if (strlen($url) > static::MAX_LENGTH) {
      throw new InvalidExternalUrlException(sprintf('The link is longer than %d characters.', static::MAX_LENGTH));
    }
    if (preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
      throw new InvalidExternalUrlException('The link contains spaces, backslashes or control characters.');
    }
    $parts = parse_url($url);
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'])) {
      throw new InvalidExternalUrlException('Enter a complete link, starting with https://.');
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      throw new InvalidExternalUrlException('Only http and https links are supported.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      throw new InvalidExternalUrlException('Links with a user name or password are not accepted.');
    }
    $host = rtrim(strtolower($parts['host']), '.');
    // Host names only: letters, digits and hyphens in dot-separated labels.
    // IP literals in brackets, "%" escapes and non-ASCII names are refused.
    if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
      throw new InvalidExternalUrlException('The link does not name a valid host.');
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : NULL;
    if ($port === ($scheme === 'https' ? 443 : 80)) {
      $port = NULL;
    }
    $segments = [];
    foreach (explode('/', $parts['path'] ?? '') as $segment) {
      $segment = rawurldecode($segment);
      if ($segment === '') {
        continue;
      }
      if ($segment === '.' || $segment === '..' || str_contains($segment, '/')) {
        throw new InvalidExternalUrlException('The path of the link is not valid.');
      }
      $segments[] = $segment;
    }
    return new self($scheme, $host, $port, $segments, $parts['query'] ?? '');
  }

  /**
   * The normalized link: scheme, host, port, path and query.
   */
  public function toString(bool $with_query = TRUE): string {
    $url = $this->scheme . '://' . $this->host . ($this->port ? ':' . $this->port : '');
    $url .= '/' . implode('/', array_map('rawurlencode', $this->segments));
    if ($with_query && $this->query !== '') {
      $url .= '?' . $this->query;
    }
    return $url;
  }

}
