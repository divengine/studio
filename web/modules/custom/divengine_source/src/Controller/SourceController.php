<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_source\Entity\SourceRef;
use Drupal\divengine_source\Entity\SourceRepository;
use Drupal\divengine_source\Git\BlobTooLargeException;
use Drupal\divengine_source\Git\GitException;
use Drupal\divengine_source\Git\GitRepository;
use Drupal\divengine_source\SourceIndex;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browsing code: repositories of a Development, the code browser, raw
 * files and indexing.
 */
class SourceController extends ControllerBase {

  const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

  protected SourceIndex $index;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->index = $container->get('divengine_source.index');
    return $instance;
  }

  /**
   * Every repository the user may see.
   */
  public function repositories(): array {
    $storage = $this->entityTypeManager->getStorage('source_repository');
    $repositories = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->sort('name')->execute());
    return $this->page() + [
      'list' => $this->section($this->t('Repositories'), count($repositories), $this->repositoryTable($repositories, TRUE)),
    ];
  }

  /**
   * The Code tab of a Development.
   */
  public function development(DevelopmentInterface $development): array {
    $storage = $this->entityTypeManager->getStorage('source_repository');
    $repositories = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->condition('development', $development->id())->sort('name')->execute());
    $add = $this->entityTypeManager->getAccessControlHandler('source_repository')->createAccess() && $development->access('update')
      ? $this->button($this->t('Register repository'), Url::fromRoute('entity.source_repository.add_form', [], ['query' => ['development' => $development->id()]]), TRUE)
      : NULL;
    return $this->page() + [
      'intro' => ['#markup' => '<p class="dv-intro">' . $this->t('The Git repositories that hold the code of this Development. The code stays in Git: Studio indexes the files of each ref and reads them on demand. Agents connected through MCP can browse and read it too.') . '</p>'],
      'list' => $this->section($this->t('Repositories'), count($repositories), $this->repositoryTable($repositories, FALSE), $add),
    ];
  }

  public function developmentTitle(DevelopmentInterface $development): string {
    return (string) $this->t('@development: code', ['@development' => $development->label()]);
  }

  /**
   * The code browser: a folder tree next to the open folder or file.
   */
  public function browse(SourceRepository $source_repository, Request $request): array|RedirectResponse {
    $repository = $source_repository;
    $ref = $this->ref($repository, $request);
    if (!$ref || !$ref->getLastSnapshot()) {
      $this->messenger()->addWarning($this->t('%repository has no indexed ref yet: index one to browse its code.', ['%repository' => $repository->label()]));
      return new RedirectResponse($repository->toUrl()->toString());
    }
    $path = trim((string) $request->query->get('path', ''), '/');
    $file = $path !== '' ? $this->index->file($ref, $path) : NULL;
    $directory = $file ? $file->directory : $path;
    $listing = $this->index->directory($ref, $directory);
    if ($path !== '' && !$file && !$listing['folders'] && !$listing['files']) {
      throw new NotFoundHttpException();
    }

    $build = $this->page();
    $build['#title'] = $repository->label();
    $build['#attached']['library'][] = 'divengine_source/browser';
    $build['#cache']['contexts'][] = 'url.query_args';
    $build['toolbar'] = $this->toolbar($repository, $ref, $path);
    $build['browser'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-code-browser']],
      'tree' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-code-tree']],
        'list' => $this->listing($repository, $ref, $directory, $listing, $file?->path),
      ],
      'main' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-code-main']],
        'content' => $file ? $this->viewer($repository, $ref, $file) : $this->folder($repository, $ref, $directory, $listing),
      ],
    ];
    return $build;
  }

  /**
   * A file as it is, for downloads and images.
   */
  public function raw(SourceRepository $source_repository, Request $request): Response {
    $ref = $this->ref($source_repository, $request);
    $file = $ref ? $this->index->file($ref, (string) $request->query->get('path', '')) : NULL;
    if (!$file || $file->entry_type !== 'blob') {
      throw new NotFoundHttpException();
    }
    try {
      $content = $this->index->read($ref, $file);
    }
    catch (GitException $e) {
      return new Response($e->getMessage(), 413, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
    $type = static::IMAGE_TYPES[strtolower((string) $file->extension)] ?? (GitRepository::isBinary($content) ? 'application/octet-stream' : 'text/plain; charset=utf-8');
    return new Response($content, 200, [
      'Content-Type' => $type,
      'Content-Disposition' => ($type === 'application/octet-stream' ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $file->filename) . '"',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
      'Cache-Control' => 'private, max-age=3600',
    ]);
  }

  /**
   * Indexes a ref now.
   */
  public function indexRef(SourceRef $source_ref): RedirectResponse {
    try {
      [$snapshot, $outcome] = $this->index->index($source_ref);
      $args = ['%ref' => $source_ref->label(), '@commit' => $snapshot->label(), '@files' => (int) $snapshot->get('file_count')->value];
      match ($outcome) {
        'indexed' => $this->messenger()->addStatus($this->t('%ref indexed at @commit: @files files.', $args)),
        'unchanged' => $this->messenger()->addStatus($this->t('%ref is already indexed at @commit.', $args)),
        'restored' => $this->messenger()->addStatus($this->t('%ref moved back to @commit, indexed before; its files were restored.', $args)),
        default => $this->messenger()->addError($this->t('Indexing %ref failed: @error', $args + ['@error' => $snapshot->get('error')->value])),
      };
    }
    catch (GitException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    return new RedirectResponse($source_ref->getRepository()->toUrl()->toString());
  }

  public function indexAccess(SourceRef $source_ref, AccountInterface $account): AccessResultInterface {
    return $source_ref->access('update', $account, TRUE);
  }

  public function browseAccess(SourceRepository $source_repository, AccountInterface $account): AccessResultInterface {
    return $source_repository->access('view', $account, TRUE);
  }

  /**
   * The ref asked for (?ref=ID), else the default one.
   */
  protected function ref(SourceRepository $repository, Request $request): ?SourceRef {
    if ($id = $request->query->get('ref')) {
      $ref = $this->entityTypeManager->getStorage('source_ref')->load($id);
      return $ref instanceof SourceRef && (string) $ref->get('repository')->target_id === (string) $repository->id() ? $ref : NULL;
    }
    return $repository->getDefaultRef();
  }

  protected function url(SourceRepository $repository, SourceRef $ref, string $path = '', string $route = 'divengine_source.browse'): Url {
    return Url::fromRoute($route, ['source_repository' => $repository->id()], ['query' => array_filter(['ref' => $ref->id(), 'path' => $path])]);
  }

  /**
   * Ref switcher and the path as breadcrumbs.
   */
  protected function toolbar(SourceRepository $repository, SourceRef $ref, string $path): array {
    $refs = array_filter($this->entityTypeManager->getStorage('source_ref')->loadByProperties(['repository' => $repository->id(), 'status' => 1]), fn ($r) => $r->getLastSnapshot());
    $switch = [];
    foreach ($refs as $candidate) {
      $switch[] = Link::fromTextAndUrl($candidate->label(), $this->url($repository, $candidate, $path))->toRenderable() + ['#attributes' => ['class' => array_filter(['dv-ref-chip', (string) $candidate->id() === (string) $ref->id() ? 'is-current' : NULL])]];
    }
    $crumbs = [Link::fromTextAndUrl($repository->get('name')->value, $this->url($repository, $ref))->toRenderable()];
    $so_far = '';
    foreach ($path === '' ? [] : explode('/', $path) as $part) {
      $so_far = ltrim($so_far . '/' . $part, '/');
      $crumbs[] = ['#markup' => '<span class="dv-code-crumbs__sep">/</span>'];
      $crumbs[] = Link::fromTextAndUrl($part, $this->url($repository, $ref, $so_far))->toRenderable();
    }
    $snapshot = $ref->getLastSnapshot();
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-code-toolbar']],
      'refs' => ['#type' => 'container', '#attributes' => ['class' => ['dv-code-refs']], 'label' => ['#markup' => '<span class="dv-code-refs__label">' . $this->t('Ref') . '</span>'], 'links' => $switch],
      'commit' => ['#markup' => '<span class="dv-code-commit">' . $this->t('commit') . ' <code>' . $snapshot->label() . '</code></span>'],
      'crumbs' => ['#type' => 'container', '#attributes' => ['class' => ['dv-code-crumbs']], 'items' => $crumbs],
      'back' => $this->button($this->t('Repository'), $repository->toUrl()),
    ];
  }

  /**
   * The folder tree column: parent, sub-folders and files of a folder.
   */
  protected function listing(SourceRepository $repository, SourceRef $ref, string $directory, array $listing, ?string $current): array {
    $items = [];
    if ($directory !== '') {
      $parent = str_contains($directory, '/') ? substr($directory, 0, strrpos($directory, '/')) : '';
      $items[] = Link::fromTextAndUrl('..', $this->url($repository, $ref, $parent))->toRenderable() + ['#attributes' => ['class' => ['dv-code-entry', 'dv-code-entry--up']]];
    }
    foreach ($listing['folders'] as $folder => $count) {
      $items[] = Link::fromTextAndUrl($folder, $this->url($repository, $ref, ltrim($directory . '/' . $folder, '/')))->toRenderable() + ['#attributes' => ['class' => ['dv-code-entry', 'dv-code-entry--folder'], 'title' => $this->formatPlural($count, '1 file', '@count files')]];
    }
    foreach ($listing['files'] as $file) {
      $items[] = Link::fromTextAndUrl($file->filename, $this->url($repository, $ref, $file->path))->toRenderable() + ['#attributes' => ['class' => array_filter(['dv-code-entry', 'dv-code-entry--file', $file->path === $current ? 'is-current' : NULL])]];
    }
    return ['#theme' => 'item_list', '#items' => $items, '#attributes' => ['class' => ['dv-code-entries']]];
  }

  /**
   * A folder: its entries with sizes, and its README when there is one.
   */
  protected function folder(SourceRepository $repository, SourceRef $ref, string $directory, array $listing): array {
    $rows = [];
    foreach ($listing['folders'] as $folder => $count) {
      $rows[] = [
        ['data' => Link::fromTextAndUrl('📁 ' . $folder, $this->url($repository, $ref, ltrim($directory . '/' . $folder, '/')))->toRenderable()],
        ['data' => ['#plain_text' => (string) $this->formatPlural($count, '1 file', '@count files')]],
      ];
    }
    $readme = NULL;
    foreach ($listing['files'] as $file) {
      $rows[] = [
        ['data' => Link::fromTextAndUrl('📄 ' . $file->filename, $this->url($repository, $ref, $file->path))->toRenderable()],
        ['data' => ['#plain_text' => (string) ByteSizeMarkup::create((int) $file->size)]],
      ];
      if (!$readme && preg_match('/^readme(\.md|\.txt)?$/i', $file->filename)) {
        $readme = $file;
      }
    }
    $build = [
      'entries' => ['#type' => 'table', '#header' => [$this->t('Name'), $this->t('Size')], '#rows' => $rows, '#empty' => $this->t('Empty folder.'), '#attributes' => ['class' => ['dv-code-folder']]],
    ];
    if ($readme) {
      $build['readme'] = ['#type' => 'container', '#attributes' => ['class' => ['dv-code-readme']], 'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $readme->filename], 'file' => $this->viewer($repository, $ref, $this->index->file($ref, $readme->path), FALSE)];
    }
    return $build;
  }

  /**
   * A file read from Git now: highlighted text with line numbers, an image,
   * or a note for binary, large or special entries.
   */
  protected function viewer(SourceRepository $repository, SourceRef $ref, object $file, bool $header = TRUE): array {
    $build = [];
    if ($header) {
      $build['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-code-file__header']],
        'name' => ['#markup' => '<strong>' . htmlspecialchars($file->path) . '</strong>'],
        'meta' => ['#markup' => '<span class="dv-code-file__meta">' . ByteSizeMarkup::create((int) $file->size) . ' · blob <code>' . substr($file->blob_sha, 0, 12) . '</code></span>'],
        'raw' => $file->entry_type === 'blob' ? $this->button($this->t('Raw'), $this->url($repository, $ref, $file->path, 'divengine_source.raw')) : [],
      ];
    }
    if ($file->entry_type !== 'blob') {
      $build['note'] = ['#markup' => '<p class="dv-code-note">' . ($file->entry_type === 'submodule' ? $this->t('Submodule at commit @sha.', ['@sha' => substr($file->blob_sha, 0, 12)]) : $this->t('Symbolic link.')) . '</p>'];
      return $build;
    }
    if (isset(static::IMAGE_TYPES[strtolower((string) $file->extension)])) {
      $build['image'] = ['#type' => 'html_tag', '#tag' => 'img', '#attributes' => ['src' => $this->url($repository, $ref, $file->path, 'divengine_source.raw')->toString(), 'alt' => $file->filename, 'class' => ['dv-code-image']]];
      return $build;
    }
    try {
      $content = $this->index->read($ref, $file);
    }
    catch (BlobTooLargeException $e) {
      $build['note'] = ['#markup' => '<p class="dv-code-note">' . htmlspecialchars($e->getMessage()) . ' ' . $this->t('Use Raw to download it.') . '</p>'];
      return $build;
    }
    catch (GitException $e) {
      $build['note'] = ['#markup' => '<p class="dv-code-note dv-code-note--error">' . htmlspecialchars($e->getMessage()) . '</p>'];
      return $build;
    }
    if (GitRepository::isBinary($content)) {
      $build['note'] = ['#markup' => '<p class="dv-code-note">' . $this->t('Binary file: use Raw to download it.') . '</p>'];
      return $build;
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
      $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    }
    $build['code'] = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#attributes' => ['class' => ['dv-code-view'], 'data-dv-code' => ''],
      'code' => [
        '#type' => 'html_tag',
        '#tag' => 'code',
        '#value' => htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '#attributes' => ['class' => ['language-' . $this->language($file)]],
      ],
    ];
    return $build;
  }

  /**
   * The highlight.js language of a file, from its name.
   */
  protected function language(object $file): string {
    $name = strtolower((string) $file->filename);
    $extension = strtolower((string) $file->extension);
    return match (TRUE) {
      in_array($name, ['dockerfile', 'containerfile'], TRUE) => 'dockerfile',
      $name === 'makefile' => 'makefile',
      str_ends_with($name, '.html.twig'), $extension === 'twig' => 'twig',
      in_array($extension, ['php', 'module', 'install', 'inc', 'theme', 'profile', 'engine'], TRUE) => 'php',
      in_array($extension, ['js', 'mjs', 'cjs', 'jsx'], TRUE) => 'javascript',
      in_array($extension, ['ts', 'tsx'], TRUE) => 'typescript',
      in_array($extension, ['yml', 'yaml'], TRUE) => 'yaml',
      in_array($extension, ['md', 'markdown'], TRUE) => 'markdown',
      in_array($extension, ['html', 'htm', 'xml', 'svg', 'bpmn', 'xsd', 'xsl'], TRUE) => 'xml',
      in_array($extension, ['sh', 'bash', 'zsh'], TRUE) => 'bash',
      in_array($extension, ['py'], TRUE) => 'python',
      in_array($extension, ['css', 'scss', 'less', 'json', 'sql', 'java', 'go', 'rs', 'rb', 'c', 'h', 'cpp', 'cs', 'kt', 'swift', 'ini', 'diff'], TRUE) => match ($extension) {
        'rs' => 'rust', 'rb' => 'ruby', 'h' => 'c', 'cs' => 'csharp', 'kt' => 'kotlin', default => $extension,
      },
      default => 'plaintext',
    };
  }

  protected function repositoryTable(array $repositories, bool $with_development): array {
    $rows = [];
    foreach ($repositories as $repository) {
      $default = $repository->getDefaultRef();
      $snapshot = $default?->getLastSnapshot();
      $row = [
        ['data' => ['#plain_text' => (string) $repository->get('code')->value]],
        ['data' => $repository->toLink($repository->get('name')->value)->toRenderable()],
      ];
      if ($with_development) {
        $row[] = ['data' => ['#plain_text' => (string) $repository->get('development')->entity?->label()]];
      }
      $rows[] = array_merge($row, [
        ['data' => ['#plain_text' => (string) $default?->label()]],
        ['data' => $snapshot ? ['#markup' => '<code>' . $snapshot->label() . '</code>'] : ['#plain_text' => (string) $this->t('Not indexed')]],
        ['data' => ['#plain_text' => $snapshot ? (string) $this->index->count($default) : '']],
        ['data' => $snapshot ? $this->button($this->t('Browse code'), Url::fromRoute('divengine_source.browse', ['source_repository' => $repository->id()])) : []],
      ]);
    }
    $header = [$this->t('Code'), $this->t('Repository')];
    if ($with_development) {
      $header[] = $this->t('Development');
    }
    $header = array_merge($header, [$this->t('Default ref'), $this->t('Commit'), $this->t('Files'), '']);
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $this->t('No repository registered yet.')];
  }

  protected function page(): array {
    return [
      '#attached' => ['library' => ['divengine_source/source']],
      '#cache' => ['tags' => ['source_repository_list', 'source_ref_list', 'source_snapshot_list'], 'contexts' => ['user']],
    ];
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

}
