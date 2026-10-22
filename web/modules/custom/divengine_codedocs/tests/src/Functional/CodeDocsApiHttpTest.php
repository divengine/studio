<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_codedocs\Functional;

use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Drupal\divengine_process\Entity\Development;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Who may publish and read through the CodeDocs API, over HTTP with Basic
 * authentication as CI accounts use it, and that refusals do not tell
 * which documentation sets exist.
 */
#[RunTestsInSeparateProcesses]
class CodeDocsApiHttpTest extends BrowserTestBase {

  protected static $modules = ['basic_auth', 'divengine_process', 'divengine_codedocs'];

  protected $defaultTheme = 'stark';

  public function testPublishingAndReadingFollowTheDevelopment(): void {
    $manager = $this->drupalCreateUser(['use unified process', 'manage code docs', 'use code docs']);
    $this->setCurrentUser($manager);
    $shop = Development::create(['name' => 'Shop']);
    $shop->save();
    $other = Development::create(['name' => 'Other']);
    $other->save();
    CodeDocSet::create(['development' => $shop->id(), 'name' => 'Shop backend', 'identifier' => 'shop-php'])->save();
    CodeDocSet::create(['development' => $other->id(), 'name' => 'Other backend', 'identifier' => 'other-php'])->save();

    $publisher = $this->drupalCreateUser(['publish code docs']);
    $reader = $this->drupalCreateUser(['use unified process', 'use code docs']);
    $document = json_decode(file_get_contents(__DIR__ . '/../../fixtures/contract.json'), TRUE);
    $this->assertSame('shop-php', $document['project']);

    // Outside the Development, publishing into an existing set and into an
    // identifier that names no set get the same answer.
    $existing = $this->post($publisher, $document);
    $missing = $this->post($publisher, ['project' => 'no-such-set'] + $document);
    $this->assertSame(403, $existing->getStatusCode());
    $this->assertSame(403, $missing->getStatusCode());
    $this->assertSame($this->message($existing, 'shop-php'), $this->message($missing, 'no-such-set'));

    // A member publishes into its Development, and only there.
    $shop->get('members')->appendItem($publisher->id());
    $shop->save();
    $published = $this->post($publisher, $document);
    $this->assertSame(200, $published->getStatusCode(), (string) $published->getBody());
    $this->assertSame(403, $this->post($publisher, ['project' => 'other-php'] + $document)->getStatusCode());
    $this->assertSame(403, $this->post($this->drupalCreateUser(['use code docs']), $document)->getStatusCode(), 'Reading does not allow publishing.');
    // Anonymous requests get the Basic authentication challenge.
    $this->assertSame(401, $this->post(NULL, $document)->getStatusCode());

    // Publishing does not allow reading what was published. A reader outside
    // the Development cannot tell the set from one that does not exist.
    foreach ([$publisher, $reader] as $user) {
      foreach (['snapshots', 'units', 'symbols'] as $operation) {
        $hidden = $this->get($user, $operation, 'shop-php');
        $absent = $this->get($user, $operation, 'no-such-set');
        $this->assertSame(403, $hidden->getStatusCode(), "$operation of a hidden set");
        $this->assertSame(403, $absent->getStatusCode(), "$operation of a missing set");
        // The publisher is refused by the route already; the reader reaches
        // the API, which answers both the same way.
        if ($user === $reader) {
          $this->assertSame((string) $hidden->getBody(), (string) $absent->getBody(), "$operation: same answer for a missing set");
        }
      }
      $sets = json_decode((string) $this->get($user, 'sets')->getBody(), TRUE);
      $this->assertNotContains('shop-php', array_column($sets ?? [], 'identifier'));
    }

    // A member of the Development reads it.
    $shop->get('members')->appendItem($reader->id());
    $shop->save();
    $this->assertSame(200, $this->get($reader, 'units', 'shop-php')->getStatusCode());
    $this->assertContains('shop-php', array_column(json_decode((string) $this->get($reader, 'sets')->getBody(), TRUE), 'identifier'));
    $this->assertSame(403, $this->get($reader, 'units', 'other-php')->getStatusCode());
  }

  protected function post(?UserInterface $user, array $document): ResponseInterface {
    return $this->getHttpClient()->request('POST', $this->buildUrl('/api/divengine/codedocs/import'), [
      'json' => $document,
      'auth' => $user ? [$user->getAccountName(), $user->passRaw] : NULL,
      'http_errors' => FALSE,
    ]);
  }

  protected function get(UserInterface $user, string $operation, string $project = ''): ResponseInterface {
    return $this->getHttpClient()->request('GET', $this->buildUrl("/api/divengine/codedocs/$operation", ['query' => ['project' => $project]]), [
      'auth' => [$user->getAccountName(), $user->passRaw],
      'http_errors' => FALSE,
    ]);
  }

  /**
   * The message of a JSON error, with the identifier it names removed.
   */
  protected function message(ResponseInterface $response, string $identifier): string {
    return str_replace($identifier, '…', (string) (json_decode((string) $response->getBody(), TRUE)['message'] ?? ''));
  }

}
