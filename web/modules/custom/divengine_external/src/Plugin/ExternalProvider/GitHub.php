<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Plugin\ExternalProvider;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_external\Attribute\ExternalProvider;
use Drupal\divengine_external\ExternalIdentity;
use Drupal\divengine_external\ExternalProviderInterface;
use Drupal\divengine_external\ExternalUrl;
use Drupal\divengine_external\InvalidExternalUrlException;

/**
 * Repositories, issues, pull requests and commits on github.com.
 *
 * Owner and repository names are case-insensitive on GitHub, so they are
 * stored lowercase. A link to a page under an issue or pull request (its
 * files or commits tab) is the same work.
 */
#[ExternalProvider(
  id: 'github',
  label: new TranslatableMarkup('GitHub'),
  hosts: ['github.com', 'www.github.com'],
)]
class GitHub extends PluginBase implements ExternalProviderInterface {

  public function kinds(): array {
    return [
      'repository' => new TranslatableMarkup('GitHub repository'),
      'issue' => new TranslatableMarkup('GitHub issue'),
      'pull_request' => new TranslatableMarkup('GitHub pull request'),
      'commit' => new TranslatableMarkup('GitHub commit'),
    ];
  }

  public function identify(ExternalUrl $url): ExternalIdentity {
    $segments = $url->segments;
    if (count($segments) < 2 || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38})$/i', $segments[0]) || !preg_match('/^[a-z0-9._-]{1,100}$/i', $segments[1])) {
      throw new InvalidExternalUrlException('A GitHub link must name an owner and a repository, e.g. https://github.com/owner/repository/pull/12.');
    }
    $owner = strtolower($segments[0]);
    $repository = strtolower(preg_replace('/\.git$/i', '', $segments[1]));
    if ($repository === '' || $repository === '.' || $repository === '..') {
      throw new InvalidExternalUrlException('The repository name of the GitHub link is not valid.');
    }
    $namespace = "$owner/$repository";
    $base = "https://github.com/$namespace";
    $what = $segments[2] ?? NULL;
    $id = $segments[3] ?? NULL;

    if ($what === NULL) {
      return new ExternalIdentity('github', 'github.com', $namespace, 'repository', $namespace, $base, $namespace);
    }
    if (in_array($what, ['issues', 'pull'], TRUE) && $id !== NULL && preg_match('/^[1-9][0-9]{0,9}$/', $id)) {
      $issue = $what === 'issues';
      return new ExternalIdentity('github', 'github.com', $namespace, $issue ? 'issue' : 'pull_request', $id, "$base/" . ($issue ? 'issues' : 'pull') . "/$id", "$namespace#$id");
    }
    if ($what === 'commit' && $id !== NULL && preg_match('/^[0-9a-f]{7,40}$/i', $id)) {
      $sha = strtolower($id);
      return new ExternalIdentity('github', 'github.com', $namespace, 'commit', $sha, "$base/commit/$sha", "$namespace@" . substr($sha, 0, 7));
    }
    throw new InvalidExternalUrlException('This GitHub link is not supported. Link a repository, an issue, a pull request or a commit.');
  }

}
