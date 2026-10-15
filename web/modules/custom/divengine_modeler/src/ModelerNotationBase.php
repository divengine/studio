<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Plugin\PluginBase;
use Drupal\divengine_modeler\Entity\DiagramInterface;

/**
 * Base class for notations, with hardened XML parsing for canvas engines.
 */
abstract class ModelerNotationBase extends PluginBase implements ModelerNotationInterface {

  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  public function family(): string {
    return $this->pluginDefinition['family'];
  }

  public function sourceFormat(): string {
    return $this->pluginDefinition['source_format'];
  }

  public function fileExtension(): string {
    return $this->pluginDefinition['file_extension'];
  }

  public function editorLibrary(): string {
    return $this->pluginDefinition['editor_library'];
  }

  public function previewLibrary(): string {
    return $this->pluginDefinition['preview_library'];
  }

  public function clientSettings(DiagramInterface $diagram): array {
    return [];
  }

  /**
   * Checks the size limit shared by every notation.
   *
   * @return string[]
   *   Error messages.
   */
  protected function checkSize(string $source): array {
    if (strlen($source) > static::MAX_SOURCE_BYTES) {
      return [sprintf('The source exceeds the %d byte limit.', static::MAX_SOURCE_BYTES)];
    }
    return [];
  }

  /**
   * Parses an XML document without DTDs, entity expansion or network access.
   *
   * @param string $xml
   *   The document.
   * @param string[] $errors
   *   Receives the parse errors.
   *
   * @return \DOMDocument|null
   *   The document, or NULL when it is not acceptable.
   */
  public static function parseXml(string $xml, array &$errors = []): ?\DOMDocument {
    if (trim($xml) === '') {
      $errors[] = 'The document is empty.';
      return NULL;
    }
    // A document type declaration is the door to entity expansion and
    // external fetches; diagram documents never need one.
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
      $errors[] = 'Document type declarations are not allowed.';
      return NULL;
    }
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
    foreach (libxml_get_errors() as $error) {
      $errors[] = sprintf('XML error on line %d: %s', $error->line, trim($error->message));
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $errors) {
      if (!$errors) {
        $errors[] = 'The document is not well-formed XML.';
      }
      return NULL;
    }
    return $document;
  }

  /**
   * Whether a value is an entity:<type>/<id> URI.
   */
  public static function isEntityUri(string $value): bool {
    return (bool) preg_match('#^entity:[a-z_][a-z0-9_]*/[A-Za-z0-9_.-]{1,128}$#', $value);
  }

  /**
   * Normalizes whitespace of extracted search text.
   */
  protected static function compactText(array $parts): string {
    $parts = array_filter(array_map(static fn ($part) => trim(preg_replace('/\s+/u', ' ', (string) $part)), $parts));
    return mb_substr(implode("\n", array_unique($parts)), 0, 100_000);
  }

}
