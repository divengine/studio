<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler_test\Plugin\ModelerNotation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_modeler\Attribute\ModelerNotation;
use Drupal\divengine_modeler\ModelerNotationBase;

/**
 * A line-based test notation.
 *
 * The first line is "SKETCH"; "box <id> <name>" declares an element and
 * "link <id> <uri>" links it.
 */
#[ModelerNotation(
  id: 'sketch',
  label: new TranslatableMarkup('Sketch'),
  family: 'text',
  source_format: 'text',
  editor_library: 'divengine_modeler/modeler',
  preview_library: 'divengine_modeler/modeler',
)]
class SketchNotation extends ModelerNotationBase {

  public function defaultSource(): string {
    return "SKETCH\n";
  }

  public function validateSource(string $source): array {
    $errors = $this->checkSize($source);
    if (!str_starts_with($source, 'SKETCH')) {
      $errors[] = 'A sketch starts with SKETCH.';
    }
    return $errors;
  }

  public function extractLinks(string $source, array $bindings = []): array {
    $links = [];
    foreach ($bindings as $key => $binding) {
      $links[] = ['element' => (string) $key, 'entity' => $binding['entity'], 'label' => $binding['label'] ?? ''];
    }
    preg_match_all('/^link (\S+) (\S+)$/m', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      if (static::isEntityUri($match[2])) {
        $links[] = ['element' => $match[1], 'entity' => $match[2], 'label' => ''];
      }
    }
    return $links;
  }

  public function extractSearchText(string $source): string {
    preg_match_all('/^box \S+ (.+)$/m', $source, $matches);
    return static::compactText($matches[1]);
  }

}
