<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Plugin\ExternalProvider;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_external\Attribute\ExternalProvider;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\ExternalIdentity;
use Drupal\divengine_external\ExternalObservation;
use Drupal\divengine_external\ExternalUrl;
use Drupal\divengine_external\InvalidExternalUrlException;
use Drupal\divengine_external\ObservableProviderInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Repositories, issues, pull requests, commits and Actions runs on github.com.
 *
 * Owner and repository names are case-insensitive on GitHub, so they are
 * stored lowercase. A link to a page under an issue, pull request or run
 * (its files tab, a job) is the same work.
 *
 * Observing asks the public REST API at api.github.com, anonymously, for the
 * title and state of public work. Request paths are built from the stored
 * identity only; redirects are not followed and answers are bounded.
 */
#[ExternalProvider(
  id: 'github',
  label: new TranslatableMarkup('GitHub'),
  hosts: ['github.com', 'www.github.com'],
)]
class GitHub extends PluginBase implements ObservableProviderInterface, ContainerFactoryPluginInterface {

  /**
   * The only host Studio asks.
   */
  const API = 'https://api.github.com';

  /**
   * Seconds to connect, and to get the whole answer.
   */
  const CONNECT_TIMEOUT = 3;

  const TIMEOUT = 5;

  /**
   * The largest answer read, in bytes.
   */
  const MAX_BYTES = 1048576;

  protected ?ClientInterface $httpClient = NULL;

  protected ?TimeInterface $time = NULL;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  public function kinds(): array {
    return [
      'repository' => new TranslatableMarkup('GitHub repository'),
      'issue' => new TranslatableMarkup('GitHub issue'),
      'pull_request' => new TranslatableMarkup('GitHub pull request'),
      'commit' => new TranslatableMarkup('GitHub commit'),
      'actions_run' => new TranslatableMarkup('GitHub Actions run'),
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
    if ($what === 'actions' && ($segments[3] ?? NULL) === 'runs' && isset($segments[4]) && preg_match('/^[1-9][0-9]{0,19}$/', $segments[4])) {
      $run = $segments[4];
      return new ExternalIdentity('github', 'github.com', $namespace, 'actions_run', $run, "$base/actions/runs/$run", "$namespace run $run");
    }
    throw new InvalidExternalUrlException('This GitHub link is not supported. Link a repository, an issue, a pull request, a commit or an Actions run.');
  }

  public function observe(ExternalReference $reference): ExternalObservation {
    $identity = $reference->identityValues();
    // The stored identity was validated when the reference was registered;
    // check it again before it becomes part of a request path.
    if (!preg_match('#^[a-z0-9][a-z0-9-]{0,38}/[a-z0-9._-]{1,100}$#', $identity['namespace']) || !preg_match('/^[0-9a-f]{1,40}$|^[a-z0-9._\/-]{1,141}$/', $identity['remote_id'])) {
      return new ExternalObservation(ExternalObservation::UNAVAILABLE, note: 'The stored identity cannot be asked about.');
    }
    $repo = '/repos/' . $identity['namespace'];
    $id = rawurlencode($identity['remote_id']);
    $path = match ($identity['kind']) {
      'repository' => $repo,
      // Issues and pull requests share numbers; the issues endpoint answers
      // for both and says which one it is.
      'issue' => "$repo/issues/$id",
      'pull_request' => "$repo/pulls/$id",
      'commit' => "$repo/commits/$id",
      'actions_run' => "$repo/actions/runs/$id",
      default => NULL,
    };
    if ($path === NULL) {
      return new ExternalObservation(ExternalObservation::UNAVAILABLE, note: 'This kind of work cannot be asked about.');
    }
    try {
      $response = $this->httpClient->request('GET', static::API . $path, [
        RequestOptions::ALLOW_REDIRECTS => FALSE,
        RequestOptions::CONNECT_TIMEOUT => static::CONNECT_TIMEOUT,
        RequestOptions::TIMEOUT => static::TIMEOUT,
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::STREAM => TRUE,
        RequestOptions::HEADERS => [
          'Accept' => 'application/vnd.github+json',
          'X-GitHub-Api-Version' => '2022-11-28',
          'User-Agent' => 'Divengine-Studio',
        ],
      ]);
    }
    catch (GuzzleException) {
      return new ExternalObservation(ExternalObservation::UNAVAILABLE, note: 'GitHub could not be reached.');
    }
    return $this->interpret($response, $identity['kind']);
  }

  /**
   * Reads an answer of the API.
   */
  protected function interpret(ResponseInterface $response, string $kind): ExternalObservation {
    $code = $response->getStatusCode();
    if ($code === 404 || $code === 410) {
      return new ExternalObservation(ExternalObservation::NOT_FOUND, note: 'GitHub does not show this work to anonymous visitors: it does not exist or is not public.');
    }
    $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
    if ($code === 429 || ($code === 403 && ($remaining === '0' || $response->hasHeader('Retry-After')))) {
      $now = $this->time->getCurrentTime();
      $retry = $response->hasHeader('Retry-After') ? $now + max(1, (int) $response->getHeaderLine('Retry-After')) : (int) $response->getHeaderLine('X-RateLimit-Reset');
      return new ExternalObservation(ExternalObservation::RATE_LIMITED, note: 'GitHub asked Studio to wait before asking again.', retryAfter: max($retry, $now + 60));
    }
    if ($code !== 200) {
      return new ExternalObservation(ExternalObservation::UNAVAILABLE, note: sprintf('GitHub answered with HTTP %d.', $code));
    }
    $body = $response->getBody()->read(static::MAX_BYTES + 1);
    if (strlen($body) > static::MAX_BYTES) {
      return new ExternalObservation(ExternalObservation::UNAVAILABLE, note: 'GitHub sent an unexpectedly large answer.');
    }
    $data = json_decode($body, TRUE);
    if (!is_array($data)) {
      return new ExternalObservation(ExternalObservation::UNAVAILABLE, note: 'GitHub sent an answer Studio cannot read.');
    }

    $note = '';
    switch ($kind) {
      case 'repository':
        $title = $data['full_name'] ?? '';
        $state = !empty($data['archived']) ? 'archived' : 'active';
        $updated = $data['pushed_at'] ?? $data['updated_at'] ?? NULL;
        break;

      case 'issue':
        $title = $data['title'] ?? '';
        $state = $data['state'] ?? '';
        $updated = $data['updated_at'] ?? NULL;
        if (isset($data['pull_request'])) {
          $note = 'GitHub reports this number as a pull request.';
          $state = !empty($data['pull_request']['merged_at']) ? 'merged' : $state;
        }
        break;

      case 'pull_request':
        $title = $data['title'] ?? '';
        $state = !empty($data['merged']) || !empty($data['merged_at']) ? 'merged' : (!empty($data['draft']) && ($data['state'] ?? '') === 'open' ? 'draft' : ($data['state'] ?? ''));
        $updated = $data['updated_at'] ?? NULL;
        break;

      case 'commit':
        $title = strtok((string) ($data['commit']['message'] ?? ''), "\n") ?: '';
        $state = 'committed';
        $updated = $data['commit']['committer']['date'] ?? NULL;
        break;

      default:
        $title = $data['display_title'] ?? $data['name'] ?? '';
        $state = $data['conclusion'] ?? $data['status'] ?? '';
        $updated = $data['updated_at'] ?? NULL;
    }
    $updated = is_string($updated) ? strtotime($updated) : FALSE;
    return new ExternalObservation(
      ExternalObservation::OK,
      static::text($title, 255),
      preg_match('/^[a-z_]{1,32}$/', (string) $state) ? (string) $state : '',
      $updated === FALSE ? NULL : $updated,
      $note,
    );
  }

  /**
   * Plain text of at most $length characters, without control characters.
   */
  protected static function text(mixed $value, int $length): string {
    return is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x20\x7F]+/u', ' ', $value) ?? ''), 0, $length) : '';
  }

}
