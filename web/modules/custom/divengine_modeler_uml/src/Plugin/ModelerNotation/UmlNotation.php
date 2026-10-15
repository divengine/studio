<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler_uml\Plugin\ModelerNotation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_modeler\Attribute\ModelerNotation;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use Drupal\divengine_modeler\ModelerNotationBase;

/**
 * UML in PlantUML text, rendered in the browser by @plantuml/core.
 *
 * The rendered SVG is regenerated on every change, so element links are
 * bindings stored next to the source, keyed by a semantic key computed from
 * the rendering (ADR-0005):
 *
 * - entity:<qualified name>        classes, use cases, actors, components...
 * - link:<from>-><to>:<link type>  relations between two of those
 * - participant:<name>             sequence diagram lifelines
 *
 * Links written in the source as [[entity:node/42 label]] are recognized too.
 */
#[ModelerNotation(
  id: 'uml',
  label: new TranslatableMarkup('UML (PlantUML)'),
  family: 'text',
  source_format: 'text',
  editor_library: 'divengine_modeler_uml/editor',
  preview_library: 'divengine_modeler_uml/preview',
  file_extension: 'puml',
  description: new TranslatableMarkup('Unified Modeling Language diagrams written as PlantUML text.'),
)]
class UmlNotation extends ModelerNotationBase {

  /**
   * Constructs that make the renderer read files or the network.
   *
   * Every include is refused (the standard library is not vendored), as are
   * themes from a URL, JSON loading, images by address and script links.
   */
  const FORBIDDEN = [
    '/^\s*!(?:include\w*|import)\b/im' => 'Includes and imports are not allowed; diagrams must be self-contained.',
    '/^\s*!theme\s+\S+\s+from\b/im' => 'Themes can only come from the bundled theme library.',
    '/%load_json\s*\(/i' => 'Loading JSON from files or addresses is not allowed.',
    '/<img\s*:\s*(?:[a-z][a-z0-9+.-]*:|\/)/i' => 'Images can only be embedded, not loaded from an address.',
    '/\[\[\s*(?:javascript|vbscript|data)\s*:/i' => 'Links to script or data addresses are not allowed.',
  ];

  public function defaultSource(): string {
    return <<<'UML'
@startuml
actor Customer
usecase "Place order" as PlaceOrder
Customer --> PlaceOrder
@enduml

UML;
  }

  public function validateSource(string $source): array {
    $errors = $this->checkSize($source);
    if ($errors) {
      return $errors;
    }
    if (!preg_match('/^\s*@start(\w+)\b/m', $source, $start)) {
      $errors[] = 'A PlantUML diagram starts with a line like @startuml.';
    }
    elseif (!preg_match('/^\s*@end' . preg_quote($start[1], '/') . '\b/mi', $source)) {
      $errors[] = sprintf('The diagram must end with @end%s.', $start[1]);
    }
    foreach (static::FORBIDDEN as $pattern => $message) {
      if (preg_match($pattern, $source)) {
        $errors[] = $message;
      }
    }
    return $errors;
  }

  public function extractLinks(string $source, array $bindings = []): array {
    $links = [];
    foreach ($bindings as $key => $binding) {
      if (is_array($binding) && static::isEntityUri($binding['entity'] ?? '')) {
        $links[] = ['element' => (string) $key, 'entity' => $binding['entity'], 'label' => (string) ($binding['label'] ?? '')];
      }
    }
    foreach (preg_split('/\R/', $source) as $index => $line) {
      preg_match_all('#\[\[\s*(entity:[a-z_][a-z0-9_]*/[A-Za-z0-9_.-]{1,128})(?:\{[^}]*\})?(?:\s+([^\]]*))?\]\]#', $line, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $links[] = ['element' => 'line:' . ($index + 1), 'entity' => $match[1], 'label' => trim($match[2] ?? '')];
      }
    }
    return $links;
  }

  public function extractSearchText(string $source): string {
    // Block comments, then line comments and directives.
    $source = preg_replace("#/'.*?'/#s", ' ', $source);
    $parts = [];
    foreach (preg_split('/\R/', $source) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, "'") || str_starts_with($line, '@') || str_starts_with($line, '!') || preg_match('/^(skinparam|hide|show|left to right|top to bottom)\b/i', $line)) {
        continue;
      }
      $parts[] = preg_replace('#\[\[[^\]]*\]\]#', '', $line);
    }
    return static::compactText($parts);
  }

  public function clientSettings(DiagramInterface $diagram): array {
    $module = \Drupal::service('extension.list.module')->getPath('divengine_modeler_uml');
    return [
      'engineUrl' => \Drupal::service('file_url_generator')->generateString($module . '/js/vendor/plantuml/plantuml.js') . '?v=1.2026.8',
      'maxSourceBytes' => static::MAX_SOURCE_BYTES,
    ];
  }

}
