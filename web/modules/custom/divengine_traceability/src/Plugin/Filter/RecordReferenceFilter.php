<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_traceability\CodeResolver;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns [[CODE]] into a link to the record with that code.
 *
 * [[UC-0002]] shows "UC-0002 Get deadline warning", always with the current
 * title; [[UC-0002|the warning]] shows the given text.
 *
 * A code the reader may not open is rendered exactly like a code no record
 * has: the same text, markup and cache metadata. Otherwise a reader could
 * tell that a record of a Development they do not belong to exists (#12).
 */
#[Filter(
  id: 'divengine_record_references',
  title: new TranslatableMarkup('Link records by code: [[REQ-0001]]'),
  description: new TranslatableMarkup('Turns [[CODE]] and [[CODE|text]] into links to the Studio record with that code, with its current title.'),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  weight: 90,
)]
class RecordReferenceFilter extends FilterBase implements ContainerFactoryPluginInterface {

  protected CodeResolver $resolver;

  protected AccountInterface $currentUser;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->resolver = $container->get('divengine_traceability.code_resolver');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);
    if (!str_contains($text, '[[')) {
      return $result;
    }
    $result->addCacheContexts(['user']);
    // Creating a record with a code, or changing who may see one (members
    // live on Developments and projects), changes how a reference renders.
    $result->addCacheTags(array_map(fn (string $type) => $type . '_list', array_keys($this->resolver->fields())));
    $result->addAttachments(['library' => ['divengine_traceability/references']]);
    $text = preg_replace_callback(CodeResolver::PATTERN, function (array $match) use ($result) {
      $code = mb_strtoupper($match[1]);
      $label = isset($match[2]) ? trim($match[2]) : NULL;
      $entity = $this->resolver->resolve($code);
      $access = $entity?->access('view', $this->currentUser, TRUE);
      if (!$entity || !$access->isAllowed()) {
        return '<span class="dv-ref dv-ref--missing" title="' . Html::escape((string) $this->t('No record you can open has this code')) . '">[[' . Html::escape($code) . ']]</span>';
      }
      $result->addCacheableDependency($entity);
      $result->addCacheableDependency($access);
      $title = $label ?? trim(str_replace(['[' . $code . ']', $code], '', (string) $entity->label()));
      $text = $label ?? $code . ($title !== '' ? ' ' . $title : '');
      return '<a class="dv-ref" href="' . Html::escape($entity->toUrl()->toString()) . '" title="' . Html::escape((string) $entity->getEntityType()->getSingularLabel() . ': ' . $entity->label()) . '">' . Html::escape($text) . '</a>';
    }, $text);
    $result->setProcessedText($text);
    return $result;
  }

  public function tips($long = FALSE) {
    return $this->t('Link any record by its code: <code>[[REQ-0001]]</code>, <code>[[UC-0002|the warning use case]]</code>.');
  }

}
