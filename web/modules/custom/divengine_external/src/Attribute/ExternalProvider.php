<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a provider of external references (GitHub, a GitLab instance…).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ExternalProvider extends Plugin {

  /**
   * @param string $id
   *   The provider ID stored on references, e.g. "github".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The provider's name, e.g. "GitHub".
   * @param string[] $hosts
   *   Hosts whose links this provider reads, lowercase. An empty list makes
   *   it the fallback for hosts no other provider claims.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly array $hosts = [],
    public readonly ?string $deriver = NULL,
  ) {}

}
