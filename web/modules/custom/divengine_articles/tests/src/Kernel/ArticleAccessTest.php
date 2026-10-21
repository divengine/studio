<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_articles\Kernel;

use Drupal\comment\Entity\Comment;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\divengine_process\Entity\Development;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Knowledge articles of a Development, the articles below them and the
 * files of their discussions are reached only by the people who see that
 * Development, after any change of Development or parent.
 */
#[RunTestsInSeparateProcesses]
class ArticleAccessTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'comment', 'taxonomy', 'node', 'menu_ui', 'file', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_knowledge', 'divengine_articles', 'divengine_thread'];

  protected UserInterface $owner;

  protected UserInterface $alpha_member;

  protected UserInterface $beta_member;

  protected Development $alpha;

  protected Development $beta;

  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Discussion files are private.
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('file_private_path', $this->container->getParameter('site.path') . '/private');
    foreach (['user', 'file', 'comment', 'artifact', 'content_moderation_state', 'trace_link', 'development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline', 'node'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['system', 'node', 'comment', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_knowledge']);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    $this->installConfig('divengine_articles');
    $this->container->get('divengine_thread.manager')->sync();

    // User 1 bypasses access; the fixtures are ordinary users.
    $this->createUser();
    $permissions = ['access content', 'use unified process', 'access comments'];
    $this->owner = $this->createUser($permissions);
    $this->alpha_member = $this->createUser($permissions);
    $this->beta_member = $this->createUser($permissions);
    $this->setCurrentUser($this->owner);
    $this->alpha = Development::create(['name' => 'Alpha', 'members' => [$this->alpha_member->id()]]);
    $this->alpha->save();
    $this->beta = Development::create(['name' => 'Beta', 'members' => [$this->beta_member->id()]]);
    $this->beta->save();
  }

  public function testSubArticlesFollowTheirParent(): void {
    $guide = $this->article('Guide', ['kb_development' => $this->alpha->id()]);
    $chapter = $this->article('Chapter', ['kb_parent' => $guide->id()]);
    $section = $this->article('Section', ['kb_parent' => $chapter->id()]);
    $public = $this->article('Public note');

    // A sub-article is created in the Development of its parent.
    $this->assertSame((string) $this->alpha->id(), (string) $section->get('kb_development')->target_id);
    $this->assertReaches($this->alpha_member, [$guide, $chapter, $section, $public], []);
    $this->assertReaches($this->beta_member, [$public], [$guide, $chapter, $section]);

    // The guide moves to Beta: everything below it moves along.
    $this->reload($guide)->set('kb_development', $this->beta->id())->save();
    $this->assertReaches($this->beta_member, [$guide, $chapter, $section], []);
    $this->assertReaches($this->alpha_member, [$public], [$guide, $chapter, $section]);

    // The chapter moves under an article of Alpha: its section follows it.
    $handbook = $this->article('Handbook', ['kb_development' => $this->alpha->id()]);
    $this->reload($chapter)->set('kb_parent', $handbook->id())->save();
    $this->assertReaches($this->alpha_member, [$handbook, $chapter, $section], [$guide]);
    $this->assertReaches($this->beta_member, [$guide], [$handbook, $chapter, $section]);
  }

  public function testLeavingTheDevelopmentClosesItsArticles(): void {
    $guide = $this->article('Guide', ['kb_development' => $this->alpha->id()]);
    $chapter = $this->article('Chapter', ['kb_parent' => $guide->id()]);
    $this->assertReaches($this->alpha_member, [$guide, $chapter], []);
    $this->alpha->set('members', [])->save();
    $this->assertReaches($this->alpha_member, [], [$guide, $chapter]);
  }

  public function testUpdateRepairsSubArticlesLeftBehind(): void {
    $guide = $this->article('Guide', ['kb_development' => $this->beta->id()]);
    $chapter = $this->article('Chapter', ['kb_parent' => $guide->id()]);
    $section = $this->article('Section', ['kb_parent' => $chapter->id()]);
    // What sites have when an article changed Development before sub-articles
    // followed their parent: the chapter and its section stayed in Alpha.
    $database = $this->container->get('database');
    foreach (['node__kb_development', 'node_revision__kb_development'] as $table) {
      $database->update($table)->fields(['kb_development_target_id' => $this->alpha->id()])->condition('entity_id', [$chapter->id(), $section->id()], 'IN')->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->container->get('node.grant_storage')->write($this->reload($chapter), divengine_articles_node_access_records($this->reload($chapter)));
    $this->container->get('node.grant_storage')->write($this->reload($section), divengine_articles_node_access_records($this->reload($section)));
    $this->assertReaches($this->alpha_member, [$chapter, $section], [$guide]);

    $this->container->get('module_handler')->loadInclude('divengine_articles', 'php', 'divengine_articles.post_update');
    $sandbox = [];
    do {
      divengine_articles_post_update_sub_articles_follow_parent($sandbox);
    } while ($sandbox['#finished'] < 1);
    $this->assertReaches($this->beta_member, [$guide, $chapter, $section], []);
    $this->assertReaches($this->alpha_member, [], [$guide, $chapter, $section]);
  }

  public function testDiscussionFilesFollowTheArticle(): void {
    $guide = $this->article('Guide', ['kb_development' => $this->alpha->id()]);
    $directory = 'private://';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->assertNotFalse(file_put_contents('private://evidence.txt', 'Confidential'));
    $file = File::create(['uri' => 'private://evidence.txt', 'uid' => $this->owner->id(), 'status' => 1]);
    $file->save();
    $field = $this->container->get('divengine_thread.manager')->field($guide);
    $this->assertNotNull($field, 'Articles have a discussion.');
    Comment::create([
      'entity_type' => 'node',
      'entity_id' => $guide->id(),
      'field_name' => $field,
      'comment_type' => $guide->get($field)->getFieldDefinition()->getSetting('comment_type'),
      'uid' => $this->owner->id(),
      'status' => 1,
      'subject' => 'Evidence',
      'thread_files' => [$file->id()],
    ])->save();

    $this->assertTrue($this->downloads($this->alpha_member, $file));
    $this->assertFalse($this->downloads($this->beta_member, $file));

    // The list of files is built per viewer: it carries the access result
    // of each file, so one viewer's list is never served to another.
    $view = $this->container->get('divengine_thread.view');
    $this->setCurrentUser($this->alpha_member);
    $visible = $view->build($this->reload($guide), $field)['attachments'];
    $this->assertStringContainsString('evidence.txt', (string) $this->container->get('renderer')->renderInIsolation($visible));
    $this->setCurrentUser($this->beta_member);
    $hidden = $view->build($this->reload($guide), $field)['attachments'];
    $this->assertArrayNotHasKey('#type', $hidden);
    $cacheability = CacheableMetadata::createFromRenderArray($hidden);
    $this->assertContains('file:' . $file->id(), $cacheability->getCacheTags());
    $this->assertContains('user', $cacheability->getCacheContexts(), 'The file list varies by viewer.');
  }

  protected function article(string $title, array $values = []): NodeInterface {
    $article = Node::create($values + ['type' => 'knowledge_article', 'title' => $title, 'status' => 1]);
    $article->save();
    return $article;
  }

  protected function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Asserts what a user reaches by direct link and in listings.
   *
   * @param \Drupal\node\NodeInterface[] $visible
   * @param \Drupal\node\NodeInterface[] $hidden
   */
  protected function assertReaches(UserInterface $user, array $visible, array $hidden): void {
    $this->nextRequest();
    $this->setCurrentUser($user);
    $listed = array_map('intval', $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(TRUE)->execute());
    foreach ($visible as $node) {
      $this->assertTrue($this->reload($node)->access('view', $user), $user->getAccountName() . ' opens ' . $node->label());
      $this->assertContains((int) $node->id(), $listed, $user->getAccountName() . ' lists ' . $node->label());
    }
    foreach ($hidden as $node) {
      $this->assertFalse($this->reload($node)->access('view', $user), $user->getAccountName() . ' cannot open ' . $node->label());
      $this->assertNotContains((int) $node->id(), $listed, $user->getAccountName() . ' does not list ' . $node->label());
    }
  }

  protected function downloads(UserInterface $user, File $file): bool {
    $this->nextRequest();
    $this->setCurrentUser($user);
    return $file->access('download', $user);
  }

  /**
   * Forgets the access decisions of this request, as the next one would.
   */
  protected function nextRequest(): void {
    drupal_static_reset();
    $etm = $this->container->get('entity_type.manager');
    foreach (['node', 'file', 'comment', 'development'] as $type) {
      $etm->getAccessControlHandler($type)->resetCache();
      $etm->getStorage($type)->resetCache();
    }
  }

}
