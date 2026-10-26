<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_external\Unit;

use Drupal\divengine_external\ExternalIdentity;
use Drupal\divengine_external\ExternalUrl;
use Drupal\divengine_external\InvalidExternalUrlException;
use Drupal\divengine_external\Plugin\ExternalProvider\GitHub;
use Drupal\divengine_external\Plugin\ExternalProvider\Link;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reading the identity of external work from links, without network (#69).
 */
class ExternalIdentityTest extends UnitTestCase {

  /**
   * Identifies a link as ExternalReferences::identify() does.
   */
  protected function identify(string $url): ExternalIdentity {
    $parsed = ExternalUrl::parse($url);
    $provider = in_array($parsed->host, ['github.com', 'www.github.com'], TRUE)
      ? new GitHub([], 'github', ['hosts' => ['github.com', 'www.github.com']])
      : new Link([], 'link', ['hosts' => []]);
    return $provider->identify($parsed);
  }

  public static function recognizedLinks(): array {
    return [
      'issue' => ['https://github.com/rafageist/ancora-goh/issues/70', 'github', 'issue', 'rafageist/ancora-goh', '70', 'https://github.com/rafageist/ancora-goh/issues/70', 'rafageist/ancora-goh#70'],
      'pull request' => ['https://github.com/rafageist/ancora-goh/pull/98', 'github', 'pull_request', 'rafageist/ancora-goh', '98', 'https://github.com/rafageist/ancora-goh/pull/98', 'rafageist/ancora-goh#98'],
      'pull request tab, case, query, fragment' => ['https://GitHub.com/RafaGeist/Ancora-GOH/pull/99/files?diff=split#r1', 'github', 'pull_request', 'rafageist/ancora-goh', '99', 'https://github.com/rafageist/ancora-goh/pull/99', 'rafageist/ancora-goh#99'],
      'www and trailing slash' => ['https://www.github.com/rafageist/ancora-goh/issues/91/', 'github', 'issue', 'rafageist/ancora-goh', '91', 'https://github.com/rafageist/ancora-goh/issues/91', 'rafageist/ancora-goh#91'],
      'commit' => ['https://github.com/a/b/commit/ABCDEF1234567', 'github', 'commit', 'a/b', 'abcdef1234567', 'https://github.com/a/b/commit/abcdef1234567', 'a/b@abcdef1'],
      'actions run and job' => ['https://github.com/a/b/actions/runs/123456/job/7', 'github', 'actions_run', 'a/b', '123456', 'https://github.com/a/b/actions/runs/123456', 'a/b run 123456'],
      'repository .git' => ['http://github.com/a/b.git', 'github', 'repository', 'a/b', 'a/b', 'https://github.com/a/b', 'a/b'],
      'other host' => ['https://Docs.Example.com:443/spec/v2/#intro', 'link', 'link', '', '', 'https://docs.example.com/spec/v2', 'docs.example.com/spec/v2'],
    ];
  }

  #[DataProvider('recognizedLinks')]
  public function testLinksAreRecognizedWithoutNetwork(string $url, string $provider, string $kind, string $namespace, string $remote_id, string $canonical, string $label): void {
    $identity = $this->identify($url);
    $this->assertSame($provider, $identity->provider);
    $this->assertSame($kind, $identity->kind);
    $this->assertSame($namespace, $identity->namespace);
    if ($remote_id !== '') {
      $this->assertSame($remote_id, $identity->remoteId);
    }
    $this->assertSame($canonical, $identity->url);
    $this->assertSame($label, $identity->label);
  }

  public static function refusedLinks(): array {
    return [
      'empty' => [''],
      'relative' => ['/github.com/a/b'],
      'javascript' => ['javascript:alert(1)'],
      'data' => ['data:text/html,<script>alert(1)</script>'],
      'ftp' => ['ftp://example.com/file'],
      'credentials' => ['https://user:secret@github.com/a/b/pull/1'],
      'user only' => ['https://token@example.com/'],
      'ip literal' => ['http://[::1]/admin'],
      'percent host' => ['https://exa%6dple.com/'],
      'space' => ['https://github.com/a/b/pull/1 extra'],
      'backslash' => ['https://github.com\\@evil.example/'],
      'dot segment' => ['https://example.com/a/%2e%2e/b'],
      'encoded slash' => ['https://example.com/a%2Fb'],
      'github without repository' => ['https://github.com/rafageist'],
      'github wiki' => ['https://github.com/a/b/wiki/Home'],
      'github issue zero' => ['https://github.com/a/b/issues/0'],
      'github issue text' => ['https://github.com/a/b/issues/x1'],
      'github bad owner' => ['https://github.com/-a/b/pull/1'],
      'github actions without run' => ['https://github.com/a/b/actions'],
      'github short sha' => ['https://github.com/a/b/commit/abc12'],
      'too long' => ['https://example.com/' . str_repeat('a', 2100)],
    ];
  }

  #[DataProvider('refusedLinks')]
  public function testUnsafeOrUnknownLinksAreRefused(string $url): void {
    $this->expectException(InvalidExternalUrlException::class);
    $this->identify($url);
  }

}
