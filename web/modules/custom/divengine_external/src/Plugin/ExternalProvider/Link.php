<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Plugin\ExternalProvider;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_external\Attribute\ExternalProvider;
use Drupal\divengine_external\ExternalIdentity;
use Drupal\divengine_external\ExternalProviderInterface;
use Drupal\divengine_external\ExternalUrl;

/**
 * Any other web page, identified by its normalized link.
 *
 * Studio knows nothing about the work behind it; the reference keeps the
 * link and the label people give it.
 */
#[ExternalProvider(
  id: 'link',
  label: new TranslatableMarkup('Web link'),
)]
class Link extends PluginBase implements ExternalProviderInterface {

  public function kinds(): array {
    return ['link' => new TranslatableMarkup('Web link')];
  }

  public function identify(ExternalUrl $url): ExternalIdentity {
    $canonical = $url->toString();
    $label = $url->host . ($url->segments ? '/' . implode('/', $url->segments) : '');
    return new ExternalIdentity('link', $url->host, '', 'link', hash('sha256', $canonical), $canonical, mb_substr($label, 0, 255));
  }

}
