<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a diagram notation.
 *
 * Plugins live in a notation module under src/Plugin/ModelerNotation.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ModelerNotation extends Plugin {

  /**
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The notation name, e.g. "BPMN 2.0".
   * @param string $family
   *   "canvas" (drawn, serialized by the engine) or "text" (written source).
   * @param string $source_format
   *   "xml", "json" or "text".
   * @param string $editor_library
   *   The library that registers the notation's editor.
   * @param string $preview_library
   *   The library that renders a preview off-screen.
   * @param string $file_extension
   *   Extension used when the source is downloaded.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   A short description.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $family,
    public readonly string $source_format,
    public readonly string $editor_library,
    public readonly string $preview_library,
    public readonly string $file_extension = 'txt',
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
