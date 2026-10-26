<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

/**
 * What a link points to, independently of how it was written.
 *
 * Two links with the same provider, host, namespace, kind and remote ID are
 * the same external work: https://GitHub.com/a/b/pull/7/files and
 * https://github.com/a/b/pull/7 are one pull request.
 */
final class ExternalIdentity {

  /**
   * @param string $provider
   *   The provider plugin ID, e.g. "github".
   * @param string $host
   *   The canonical host, e.g. "github.com".
   * @param string $namespace
   *   Where the work lives on that host, e.g. "owner/repository"; empty when
   *   the provider has no such notion.
   * @param string $kind
   *   The kind of work, e.g. "issue", "pull_request", "commit".
   * @param string $remoteId
   *   Its stable identifier within the namespace, e.g. "98" or a commit SHA.
   * @param string $url
   *   The canonical link to it.
   * @param string $label
   *   A short default label, e.g. "owner/repository#98".
   */
  public function __construct(
    public readonly string $provider,
    public readonly string $host,
    public readonly string $namespace,
    public readonly string $kind,
    public readonly string $remoteId,
    public readonly string $url,
    public readonly string $label,
  ) {}

  /**
   * The values of the identity fields of a reference.
   *
   * @return array<string, string>
   */
  public function fields(): array {
    return [
      'provider' => $this->provider,
      'host' => $this->host,
      'namespace' => $this->namespace,
      'kind' => $this->kind,
      'remote_id' => $this->remoteId,
    ];
  }

}
