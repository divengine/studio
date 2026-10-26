<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_external\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\ExternalObservation;
use Drupal\divengine_external\ExternalObservations;
use Drupal\divengine_process\Entity\Development;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reading the state of public GitHub work, on request and read-only (#70).
 *
 * No test reaches the network: the HTTP client answers from a queue and
 * records what Studio asked.
 */
#[RunTestsInSeparateProcesses]
class ExternalObservationTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user'];

  protected static MockHandler $answers;

  protected static array $requests = [];

  protected UserInterface $member;

  protected Development $development;

  public function register(ContainerBuilder $container): void {
    parent::register($container);
    static::$answers = new MockHandler();
    static::$requests = [];
    $stack = HandlerStack::create(static::$answers);
    $stack->push(Middleware::history(static::$requests));
    $container->register('http_client', Client::class)->addArgument(['handler' => $stack]);
  }

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user']);
    $this->container->get('module_installer')->install(['divengine_external']);
    $this->createUser();
    $this->member = $this->createUser(['use unified process', 'view artifact', 'view trace links', 'create trace links', 'link external work']);
    $this->development = Development::create(['name' => 'Alpha', 'members' => [$this->member->id()]]);
    $this->development->save();
    $this->setCurrentUser($this->member);
  }

  protected function observations(): ExternalObservations {
    return $this->container->get('divengine_external.observations');
  }

  protected function reference(string $url): ExternalReference {
    $reference = ExternalReference::create(['development' => $this->development->id(), 'url' => $url]);
    $reference->save();
    return $reference;
  }

  protected function enable(): void {
    $this->config('divengine_external.settings')->set('observe_github', TRUE)->save();
  }

  protected function json(array $data, int $status = 200, array $headers = []): Response {
    return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($data));
  }

  /**
   * Lets the reference be asked about again, as if a minute had passed.
   */
  protected function age(ExternalReference $reference): void {
    $this->container->get('database')->update('divengine_external_observation')
      ->expression('observed', 'observed - 120')
      ->condition('reference_id', $reference->id())
      ->execute();
  }

  public function testNothingIsAskedUnlessTheSiteAllowsIt(): void {
    $issue = $this->reference('https://github.com/rafageist/ancora-goh/issues/70');
    $this->assertFalse($this->observations()->isEnabled($issue), 'Off by default.');
    $this->assertNull($this->observations()->refresh($issue, $this->member));

    $this->enable();
    $link = $this->reference('https://docs.example.com/spec');
    $this->assertFalse($this->observations()->isEnabled($link), 'Only GitHub is asked.');
    $this->assertNull($this->observations()->refresh($link, $this->member));
    $this->assertSame([], static::$requests);
  }

  public function testIssueIsReadAnonymouslyFromTheApiOnly(): void {
    $this->enable();
    $issue = $this->reference('https://github.com/RafaGeist/ancora-goh/issues/70');
    static::$answers->append($this->json(['title' => "Characterize\x07 <b>D1–D9</b>", 'state' => 'open', 'updated_at' => '2026-10-20T10:00:00Z']));

    $observation = $this->observations()->refresh($issue, $this->member);
    $this->assertSame(ExternalObservation::OK, $observation->status);
    $this->assertCount(1, static::$requests);
    /** @var \GuzzleHttp\Psr7\Request $request */
    $request = static::$requests[0]['request'];
    $this->assertSame('https://api.github.com/repos/rafageist/ancora-goh/issues/70', (string) $request->getUri());
    $this->assertSame('GET', $request->getMethod());
    $this->assertFalse($request->hasHeader('Authorization'), 'No credentials are sent.');
    $this->assertFalse(static::$requests[0]['options']['allow_redirects']);
    $this->assertSame(5, static::$requests[0]['options']['timeout']);

    $stored = $this->observations()->get($issue);
    $this->assertSame('Characterize <b>D1–D9</b>', $stored->title, 'Control characters are dropped; markup is kept as text.');
    $this->assertSame('open', $stored->state);
    $this->assertSame(strtotime('2026-10-20T10:00:00Z'), (int) $stored->updated);
    $this->assertSame((string) $this->member->id(), (string) $stored->observed_by);

    // Observing creates no revision of the reference.
    $this->assertSame((int) $issue->getRevisionId(), (int) ExternalReference::load($issue->id())->getRevisionId());

    // The page shows it as GitHub's state, escaped.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('external_reference')->view(ExternalReference::load($issue->id()));
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Observed on GitHub', $html);
    $this->assertStringContainsString('not a Studio decision', $html);
    $this->assertStringContainsString('&lt;b&gt;D1–D9&lt;/b&gt;', $html);
    $this->assertStringNotContainsString('<b>D1–D9</b>', $html);
  }

  public function testEachReferenceIsAskedAtMostOnceAMinute(): void {
    $this->enable();
    $pull = $this->reference('https://github.com/a/b/pull/98');
    static::$answers->append($this->json(['title' => 'Tests', 'state' => 'open', 'merged' => FALSE, 'draft' => TRUE]));
    $this->assertSame('draft', $this->observations()->refresh($pull, $this->member)->state);
    $this->assertNull($this->observations()->refresh($pull, $this->member));
    $this->assertNotNull($this->observations()->nextAllowed($pull));
    $this->assertCount(1, static::$requests);

    $this->age($pull);
    static::$answers->append($this->json(['title' => 'Tests', 'state' => 'closed', 'merged' => TRUE]));
    $this->assertSame('merged', $this->observations()->refresh($pull, $this->member)->state);
    $this->assertSame('https://api.github.com/repos/a/b/pulls/98', (string) static::$requests[1]['request']->getUri());
  }

  public function testIssueNumberOfAPullRequestIsReported(): void {
    $this->enable();
    $issue = $this->reference('https://github.com/a/b/issues/99');
    static::$answers->append($this->json(['title' => 'Implement', 'state' => 'closed', 'pull_request' => ['merged_at' => '2026-10-21T00:00:00Z']]));
    $observation = $this->observations()->refresh($issue, $this->member);
    $this->assertSame('merged', $observation->state);
    $this->assertSame('GitHub reports this number as a pull request.', $observation->note);
    $this->assertSame('issue', ExternalReference::load($issue->id())->get('kind')->value, 'The identity is not rewritten.');
  }

  public function testUnusableAnswers(): void {
    $this->enable();
    $cases = [
      'not found' => [new Response(404), ExternalObservation::NOT_FOUND],
      'redirect not followed' => [new Response(301, ['Location' => 'https://evil.example/']), ExternalObservation::UNAVAILABLE],
      'server error' => [new Response(502), ExternalObservation::UNAVAILABLE],
      'not JSON' => [new Response(200, [], '<html>'), ExternalObservation::UNAVAILABLE],
      'too large' => [new Response(200, [], str_repeat(' ', 1048577)), ExternalObservation::UNAVAILABLE],
      'timeout' => [new ConnectException('Timed out', new Request('GET', 'https://api.github.com/')), ExternalObservation::UNAVAILABLE],
    ];
    $n = 1;
    foreach ($cases as $case => [$answer, $status]) {
      $reference = $this->reference('https://github.com/a/b/issues/' . $n++);
      static::$answers->append($answer);
      $this->assertSame($status, $this->observations()->refresh($reference, $this->member)->status, $case);
      $this->assertSame($status, $this->observations()->get($reference)->status, $case);
    }
    $this->assertCount(count($cases), static::$requests, 'One request each; the redirect was not followed.');
  }

  public function testRateLimitStopsEveryoneUntilGitHubAllowsIt(): void {
    $this->enable();
    $first = $this->reference('https://github.com/a/b/issues/1');
    $second = $this->reference('https://github.com/a/b/commit/' . str_repeat('a', 40));
    static::$answers->append(new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 3600)]));
    $observation = $this->observations()->refresh($first, $this->member);
    $this->assertSame(ExternalObservation::RATE_LIMITED, $observation->status);
    $this->assertGreaterThan(time() + 3000, $observation->retryAfter);

    $this->assertNull($this->observations()->refresh($second, $this->member), 'Nobody asks GitHub while it asked to wait.');
    $this->assertNotNull($this->observations()->nextAllowed($second));
    $this->assertCount(1, static::$requests);
  }

  public function testCommitsRunsAndRepositories(): void {
    $this->enable();
    static::$answers->append(
      $this->json(['commit' => ['message' => "Fix D3\n\nLong body", 'committer' => ['date' => '2026-10-19T08:00:00Z']]]),
      $this->json(['display_title' => 'CI', 'status' => 'completed', 'conclusion' => 'success', 'updated_at' => '2026-10-19T09:00:00Z']),
      $this->json(['full_name' => 'a/b', 'archived' => TRUE]),
    );
    $commit = $this->observations()->refresh($this->reference('https://github.com/a/b/commit/abcdef1'), $this->member);
    $this->assertSame(['Fix D3', 'committed'], [$commit->title, $commit->state]);
    $run = $this->observations()->refresh($this->reference('https://github.com/a/b/actions/runs/123456/job/7'), $this->member);
    $this->assertSame(['CI', 'success'], [$run->title, $run->state]);
    $repository = $this->observations()->refresh($this->reference('https://github.com/a/b'), $this->member);
    $this->assertSame(['a/b', 'archived'], [$repository->title, $repository->state]);
    $this->assertSame([
      'https://api.github.com/repos/a/b/commits/abcdef1',
      'https://api.github.com/repos/a/b/actions/runs/123456',
      'https://api.github.com/repos/a/b',
    ], array_map(fn ($entry) => (string) $entry['request']->getUri(), static::$requests));
  }

  public function testDeletingAReferenceForgetsItsObservation(): void {
    $this->enable();
    $issue = $this->reference('https://github.com/a/b/issues/5');
    static::$answers->append($this->json(['title' => 'x', 'state' => 'open']));
    $this->observations()->refresh($issue, $this->member);
    $issue->delete();
    $this->assertSame(0, (int) $this->container->get('database')->select('divengine_external_observation')->countQuery()->execute()->fetchField());
  }

}
