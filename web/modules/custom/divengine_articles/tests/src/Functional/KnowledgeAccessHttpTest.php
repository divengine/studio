<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_articles\Functional;

use Drupal\comment\Entity\Comment;
use Drupal\divengine_process\Entity\Development;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Articles, their discussions and files over HTTP, with warm caches (#12).
 *
 * A member reaches the articles of their Development by direct link, its
 * comments and its private files; after leaving it, none of those, even
 * when the pages were cached for them before. [[CODE]] references never
 * tell a reader that a record they cannot open exists.
 */
#[RunTestsInSeparateProcesses]
class KnowledgeAccessHttpTest extends BrowserTestBase {

  protected static $modules = ['node', 'comment', 'file', 'filter', 'text', 'path', 'page_cache', 'dynamic_page_cache', 'divengine_process', 'divengine_traceability', 'divengine_knowledge'];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    // Articles expect the text_with_summary body storage of the Studio base
    // recipe; the testing site has an unused text_long one.
    FieldStorageConfig::loadByName('node', 'body')?->delete();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    $this->container->get('module_installer')->install(['divengine_articles', 'divengine_thread']);
    $this->rebuildContainer();
    $this->container->get('divengine_thread.manager')->sync();
    FilterFormat::create([
      'format' => 'refs',
      'name' => 'References',
      'filters' => ['divengine_record_references' => ['status' => TRUE]],
    ])->save();
    node_access_rebuild();
  }

  public function testMembershipAndReferences(): void {
    $permissions = ['access content', 'use unified process', 'access comments', 'use text format refs'];
    $owner = $this->drupalCreateUser($permissions);
    $member = $this->drupalCreateUser($permissions);
    $outsider = $this->drupalCreateUser($permissions);

    $this->setCurrentUser($owner);
    $alpha = Development::create(['name' => 'Alpha', 'uid' => $owner->id(), 'members' => [$member->id()]]);
    $alpha->save();
    $guide = $this->article('Alpha guide', ['kb_development' => $alpha->id(), 'uid' => $owner->id()]);
    $code = (string) $guide->get('kb_code')->value;
    $this->assertNotSame('', $code);
    // An organisation-wide article that mentions the guide and a code no
    // record has.
    $note = $this->article('Public note', [
      'uid' => $owner->id(),
      'body' => ['value' => "<p>See [[$code]] and [[KB-9999]].</p>", 'format' => 'refs'],
    ]);

    $thread = $this->container->get('divengine_thread.manager')->field($guide);
    $directory = 'private://threads';
    $this->container->get('file_system')->prepareDirectory($directory, 1);
    file_put_contents("$directory/minutes.txt", 'Private minutes');
    $file = File::create(['uri' => "$directory/minutes.txt", 'uid' => $owner->id(), 'status' => 1]);
    $file->save();
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $guide->id(),
      'field_name' => $thread,
      'comment_type' => $guide->get($thread)->getFieldDefinition()->getSetting('comment_type'),
      'uid' => $owner->id(),
      'status' => 1,
      'subject' => 'Kick-off',
      'comment_body' => ['value' => 'Private discussion', 'format' => 'plain_text'],
      'thread_files' => [$file->id()],
    ]);
    $comment->save();
    $download = $file->createFileUrl();

    // The member reaches everything, and warms the caches.
    $this->drupalLogin($member);
    $this->assertReaches($guide, $comment, $download);
    $this->drupalGet($note->toUrl());
    $this->assertSession()->linkExists("$code Alpha guide");
    $this->assertSession()->responseContains('[[KB-9999]]');

    // The outsider reaches none of it, and reads the reference to the guide
    // exactly as the one to a code no record has.
    $this->drupalLogin($outsider);
    $this->assertDoesNotReach($guide, $comment, $download);
    $this->assertReferencesLookAlike($note, $code);

    // The member leaves the Development: the pages cached for them are not
    // served again.
    $alpha->set('members', [])->save();
    $this->drupalLogin($member);
    $this->assertDoesNotReach($guide, $comment, $download);
    $this->assertReferencesLookAlike($note, $code);

    // The owner still reaches everything.
    $this->drupalLogin($owner);
    $this->assertReaches($guide, $comment, $download);
  }

  protected function assertReaches(NodeInterface $article, Comment $comment, string $download): void {
    $this->drupalGet($article->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private discussion');
    $this->drupalGet($comment->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($download);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Private minutes');
  }

  protected function assertDoesNotReach(NodeInterface $article, Comment $comment, string $download): void {
    foreach ([$article->toUrl()->toString(), $comment->toUrl()->toString(), $download] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
      $this->assertSession()->responseNotContains('Alpha guide');
      $this->assertSession()->responseNotContains('Private discussion');
      $this->assertSession()->responseNotContains('Private minutes');
    }
  }

  /**
   * Asserts that the hidden code and the unknown one render the same way.
   */
  protected function assertReferencesLookAlike(NodeInterface $note, string $code): void {
    $this->drupalGet($note->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('Alpha guide');
    $this->assertSession()->linkNotExists($code);
    $html = $this->getSession()->getPage()->getContent();
    $this->assertSame(1, preg_match('#<p>See (<span[^>]*>\[\[' . preg_quote($code, '#') . '\]\]</span>) and (<span[^>]*>\[\[KB-9999\]\]</span>)\.</p>#', $html, $match), 'Both references are rendered.');
    $this->assertSame($match[2], str_replace($code, 'KB-9999', $match[1]), 'The hidden code is rendered like the unknown one.');
  }

  protected function article(string $title, array $values = []): NodeInterface {
    $article = Node::create($values + ['type' => 'knowledge_article', 'title' => $title, 'status' => 1]);
    $article->save();
    return $article;
  }

}
