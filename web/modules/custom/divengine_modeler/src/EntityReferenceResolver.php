<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Finds the entities that diagram elements can link to.
 */
class EntityReferenceResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected UrlMatcherInterface $router,
    protected AliasManagerInterface $aliasManager,
  ) {}

  /**
   * The entity types offered as link targets that exist on this site.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface[]
   */
  public function targetTypes(): array {
    $types = [];
    foreach ($this->configFactory->get('divengine_modeler.settings')->get('link_target_types') ?? [] as $id) {
      $definition = $this->entityTypeManager->getDefinition($id, FALSE);
      if ($definition && $definition->entityClassImplements(ContentEntityInterface::class) && $definition->hasKey('label')) {
        $types[$id] = $definition;
      }
    }
    return $types;
  }

  /**
   * The entity:<type>/<id> URI of an entity.
   */
  public static function uriFor(EntityInterface $entity): string {
    return 'entity:' . $entity->getEntityTypeId() . '/' . $entity->id();
  }

  /**
   * Loads the entity behind a URI, or NULL.
   */
  public function load(string $uri): ?EntityInterface {
    $target = LinkIndex::parseUri($uri);
    if (!$target || !$this->entityTypeManager->hasDefinition($target[0])) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage($target[0])->load($target[1]);
  }

  /**
   * Searches link targets by label among the entities the user can view.
   *
   * @return array<int, array{entity: string, label: string, type: string, url: string}>
   */
  public function search(string $text, AccountInterface $account, int $limit = 20): array {
    $text = trim($text);
    if (mb_strlen($text) < 2) {
      return [];
    }
    $results = [];
    foreach ($this->targetTypes() as $id => $definition) {
      $storage = $this->entityTypeManager->getStorage($id);
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition($definition->getKey('label'), $text, 'CONTAINS')
        ->range(0, $limit);
      foreach ($storage->loadMultiple($query->execute()) as $entity) {
        if ($entity->access('view', $account)) {
          $results[] = $this->describe($entity);
        }
      }
    }
    usort($results, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return array_slice($results, 0, $limit);
  }

  /**
   * Resolves pasted input: an entity URI, a site path or a URL of this site.
   */
  public function resolve(string $input, string $host = ''): ?EntityInterface {
    $input = trim($input);
    if ($input === '') {
      return NULL;
    }
    if (str_starts_with($input, 'entity:')) {
      return $this->load($input);
    }
    if (preg_match('#^https?://([^/]+)(/.*)?$#i', $input, $matches)) {
      if ($host === '' || strcasecmp($matches[1], $host) !== 0) {
        return NULL;
      }
      $input = $matches[2] ?? '/';
    }
    if (!str_starts_with($input, '/')) {
      return NULL;
    }
    $path = $this->aliasManager->getPathByAlias(strtok($input, '?#'));
    try {
      $parameters = $this->router->match($path);
    }
    catch (\Exception) {
      return NULL;
    }
    foreach ($parameters as $value) {
      if ($value instanceof ContentEntityInterface && isset($this->targetTypes()[$value->getEntityTypeId()])) {
        return $value;
      }
    }
    return NULL;
  }

  /**
   * The picker's description of an entity.
   *
   * @return array{entity: string, label: string, type: string, url: string}
   */
  public function describe(EntityInterface $entity): array {
    return [
      'entity' => static::uriFor($entity),
      'label' => (string) $entity->label(),
      'type' => (string) $entity->getEntityType()->getSingularLabel(),
      'url' => $entity->hasLinkTemplate('canonical') ? $entity->toUrl()->toString() : '',
    ];
  }

}
