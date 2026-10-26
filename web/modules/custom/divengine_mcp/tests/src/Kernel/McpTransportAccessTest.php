<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp\Kernel;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Drupal\divengine_database\Entity\SchemaDatabase;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_source\Entity\SourceRepository;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What MCP clients read through /mcp/post, by credential and Development.
 *
 * Covers the MCP part of C10 (#13): token and OAuth access, revoked
 * credentials, and private requirements, code, documentation and database
 * schemas of a Development the account does not see.
 */
#[RunTestsInSeparateProcesses]
class McpTransportAccessTest extends KernelTestBase {

  use McpTransportTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user'];

  /**
   * The mcp module has no schema for its plugins' settings.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  protected Development $alpha;

  protected Development $beta;

  protected function setUp(): void {
    parent::setUp();
    $this->setUpMcpSite(['divengine_process', 'divengine_source', 'divengine_database', 'divengine_codedocs']);
    // User 1 bypasses access; never use it as a client.
    $this->createUser();

    $this->alpha = Development::create(['name' => 'Alpha']);
    $this->alpha->save();
    $this->beta = Development::create(['name' => 'Beta']);
    $this->beta->save();
    foreach (['alpha' => $this->alpha, 'beta' => $this->beta] as $name => $development) {
      Artifact::create([
        'type' => 'requirement',
        'title' => "Requirement of $name",
        'development' => $development->id(),
        'moderation_state' => 'draft',
      ])->save();
      SchemaDatabase::create(['development' => $development->id(), 'name' => "Database of $name", 'identifier' => "db-$name", 'engine' => 'postgresql'])->save();
      CodeDocSet::create(['development' => $development->id(), 'name' => "Docs of $name", 'identifier' => "docs-$name"])->save();
      SourceRepository::create(['development' => $development->id(), 'name' => "Repository of $name", 'path' => "/srv/git/$name"])->save();
    }
  }

  /**
   * Adds an account to a Development.
   */
  protected function addMember(Development $development, int|string $uid): void {
    $development = Development::load($development->id());
    $development->get('members')->appendItem($uid);
    $development->save();
  }

  /**
   * Reads a requirement collection through the JSON:API tool.
   */
  protected function requirements(string $authorization): string {
    return $this->callTool('jsonapi_jsonapi_read', ['resource_type' => 'artifact--requirement', 'fields' => ['artifact--requirement' => 'title']], $authorization);
  }

  public function testCredentialsAreRequiredAndRevocable(): void {
    $this->assertSame(401, $this->mcpRequest('tools/list', [], NULL)->getStatusCode());

    $bot = $this->createUser([], 'mcp_bot');
    $bot->addRole('mcp_agent');
    $bot->save();
    $old = $this->grantMcpToken($bot);
    $this->assertContains('general_info', $this->toolNames($this->basic()));
    $this->assertSame(401, $this->mcpRequest('tools/list', [], $this->basic('not-the-token'))->getStatusCode());

    // Regenerating the token revokes the old one.
    $this->grantMcpToken($bot);
    $this->assertSame(401, $this->mcpRequest('tools/list', [], $this->basic($old))->getStatusCode());
    $this->assertSame(200, $this->mcpRequest('tools/list', [], $this->basic())->getStatusCode());

    // A blocked service account no longer authenticates.
    $bot->block()->save();
    $this->assertNotSame(200, $this->mcpRequest('tools/list', [], $this->basic())->getStatusCode());

    $person = $this->createUser([]);
    $person->addRole('mcp_user');
    $person->save();
    $bearer = 'Bearer ' . $this->oauthToken($person);
    $this->assertContains('general_info', $this->toolNames($bearer));

    // Revoking the token (People › Simple OAuth) ends access at once.
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_token');
    $storage->delete($storage->loadByProperties(['bundle' => 'access_token', 'auth_user_id' => $person->id()]));
    $response = $this->mcpRequest('tools/list', [], $bearer);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertStringStartsWith('Bearer resource_metadata=', (string) $response->headers->get('WWW-Authenticate'));
  }

  public function testNoToolWrites(): void {
    $bot = $this->createUser([], 'mcp_bot');
    $bot->addRole('mcp_agent');
    $bot->save();
    $this->grantMcpToken($bot);
    $tools = $this->toolNames($this->basic());
    $this->assertNotEmpty($tools);
    foreach ($tools as $tool) {
      $this->assertDoesNotMatchRegularExpression('/create|update|delete|edit|write|save|publish/i', $tool, 'Read-only credentials list no write tools.');
    }
  }

  public function testServiceAccountSeesOnlyItsDevelopments(): void {
    $bot = $this->createUser([], 'mcp_bot');
    $bot->addRole('mcp_agent');
    $bot->save();
    $this->grantMcpToken($bot);
    $basic = $this->basic();

    // Before it is a member of anything, nothing private comes back.
    $this->assertStringNotContainsString('Requirement of', $this->requirements($basic));
    foreach (['database_databases', 'codedocs_sets', 'code_repositories'] as $tool) {
      $this->assertStringNotContainsString('alpha', mb_strtolower($this->callTool($tool, [], $basic)), $tool);
    }

    $this->addMember($this->alpha, $bot->id());
    $this->assertStringContainsString('Requirement of alpha', $this->requirements($basic));
    $this->assertStringNotContainsString('Requirement of beta', $this->requirements($basic));
    $expected = [
      'database_databases' => 'Database of',
      'codedocs_sets' => 'Docs of',
      'code_repositories' => 'Repository of',
    ];
    foreach ($expected as $tool => $label) {
      $text = $this->callTool($tool, [], $basic);
      $this->assertStringContainsString("$label alpha", $text, $tool);
      $this->assertStringNotContainsString("$label beta", $text, $tool);
    }

    // Naming a Development it does not see answers like an unknown one.
    $beta = $this->callTool('database_databases', ['development' => $this->beta->get('code')->value], $basic);
    $unknown = $this->callTool('database_databases', ['development' => 'DEV-9999'], $basic);
    $this->assertSame($unknown, $beta);
    $this->assertStringNotContainsString('Beta', $beta);

    // Reading a hidden requirement by its UUID reveals nothing about it.
    $hidden = $this->container->get('entity_type.manager')->getStorage('artifact')->loadByProperties(['title' => 'Requirement of beta']);
    $text = $this->callTool('jsonapi_jsonapi_read', ['resource_type' => 'artifact--requirement', 'uuid' => reset($hidden)->uuid()], $basic);
    $this->assertStringNotContainsString('Requirement of beta', $text);

    // Leaving the Development removes access on the next request.
    $alpha = Development::load($this->alpha->id());
    $alpha->set('members', [])->save();
    $this->assertStringNotContainsString('Requirement of alpha', $this->requirements($basic));
    $this->assertStringNotContainsString('Database of alpha', $this->callTool('database_databases', [], $basic));
  }

  public function testOauthTokenSeesWhatItsPersonSees(): void {
    $person = $this->createUser([]);
    $person->addRole('mcp_user');
    $person->save();
    $this->addMember($this->beta, $person->id());
    $bearer = 'Bearer ' . $this->oauthToken($person);

    // The person uses the Unified Process only through the mcp scope's role:
    // the token's permissions are those both grant, so it reads nothing.
    $this->assertStringNotContainsString('Requirement of', $this->requirements($bearer));

    // Changing the person's roles revokes their tokens; the client
    // authorizes again.
    $person->addRole('mcp_agent');
    $person->save();
    $revoked = $bearer;
    $bearer = 'Bearer ' . $this->oauthToken($person);
    $text = $this->requirements($bearer);
    $this->assertStringContainsString('Requirement of beta', $text);
    $this->assertStringNotContainsString('Requirement of alpha', $text);
    $this->assertStringContainsString('Database of beta', $this->callTool('database_databases', [], $bearer));
    $this->assertStringNotContainsString('Database of alpha', $this->callTool('database_databases', [], $bearer));
    $this->assertSame(401, $this->mcpRequest('tools/list', [], $revoked)->getStatusCode());
  }

}
