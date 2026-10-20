<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_traceability\Functional;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** HTTP/JSON:API and cached collections must preserve endpoint privacy. */
#[RunTestsInSeparateProcesses]
class TraceAccessHttpTest extends BrowserTestBase {

  protected static $modules = ['divengine_project', 'jsonapi', 'page_cache', 'dynamic_page_cache', 'trace_access_test'];

  protected $defaultTheme = 'stark';

  public function testPrivateTraceAndMembershipChanges(): void {
    // jsonrpc 2.1.x prints this PHP 8.4 deprecation during installation, even
    // though PHPUnit also records it. Accept only that known upstream output
    // (or none after it is fixed); keep strict output checks for anything else.
    $notice = preg_quote('Deprecated: Drupal\\jsonrpc\\Annotation\\JsonRpcMethod::access(): Implicitly marking parameter $account as nullable is deprecated, the explicit nullable type must be used instead in ', '/');
    $this->expectOutputRegex('/\A(?:\s*' . $notice . '[^\r\n]+JsonRpcMethod\.php on line \d+\s*)?\z/');
    $permissions = ['view trace links', 'view artifact', 'use projects', 'use mcp server'];
    $owner = $this->drupalCreateUser($permissions);
    $member = $this->drupalCreateUser($permissions);
    $outsider = $this->drupalCreateUser($permissions);
    $project = Project::create(['name' => 'Private HTTP project', 'project_key' => 'HTTP', 'uid' => $owner->id(), 'members' => [$member->id()]]); $project->save();
    $artifact = Artifact::create(['type' => 'risk', 'title' => 'Public risk', 'moderation_state' => 'published']); $artifact->save();
    $link = TraceLink::create(['source_entity_type' => 'project', 'source_entity_id' => $project->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $artifact->id(), 'link_type' => 'references', 'rationale' => 'Private HTTP rationale']); $link->save();
    $collection = '/jsonapi/trace_link/trace_link';
    $resource = $collection . '/' . $link->uuid();
    $this->drupalLogin($member);
    $this->drupalGet($resource);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Private HTTP rationale');
    $this->drupalGet($collection);
    $this->assertSession()->responseContains($link->uuid());
    $this->drupalGet('/trace-access-test/mcp/' . $link->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Private HTTP rationale');
    // Warm caches, then revoke membership. The next HTTP request is separate
    // from the writer and must not reuse its earlier privileged response.
    $project->set('members', [])->save();
    $this->drupalGet($collection);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains($link->uuid());
    $this->assertSession()->responseNotContains('Private HTTP rationale');
    $this->drupalGet('/trace-access-test/mcp/all');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains($link->uuid());
    $this->drupalGet('/trace-access-test/mcp/' . $link->uuid());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($resource);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->responseNotContains('Private HTTP project');
    $this->drupalGet('/trace-link/' . $link->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($outsider);
    $this->drupalGet($collection);
    $this->assertSession()->responseNotContains($link->uuid());
    $this->assertSession()->responseNotContains('Private HTTP rationale');
    $this->drupalLogin($owner);
    $this->drupalGet($resource);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Private HTTP rationale');
    $this->drupalGet('/trace-access-test/mcp/' . $link->uuid());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Private HTTP rationale');
  }

}
