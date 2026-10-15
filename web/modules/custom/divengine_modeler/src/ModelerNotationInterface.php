<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\divengine_modeler\Entity\DiagramInterface;

/**
 * A diagram notation: how its source is validated, indexed and edited.
 *
 * The platform never names a notation; it finds them through the
 * plugin.manager.modeler_notation service.
 */
interface ModelerNotationInterface {

  /**
   * Largest accepted source, in bytes.
   */
  const MAX_SOURCE_BYTES = 1_000_000;

  public const FAMILY_CANVAS = 'canvas';

  public const FAMILY_TEXT = 'text';

  public function getPluginId();

  public function label(): string;

  /**
   * "canvas" or "text".
   */
  public function family(): string;

  public function sourceFormat(): string;

  public function fileExtension(): string;

  public function editorLibrary(): string;

  public function previewLibrary(): string;

  /**
   * The source of a new, empty diagram.
   */
  public function defaultSource(): string;

  /**
   * Checks the structure and safety of a source.
   *
   * @return string[]
   *   Error messages; empty when the source is acceptable.
   */
  public function validateSource(string $source): array;

  /**
   * Extracts the element links of a diagram (ADR-0005).
   *
   * @param string $source
   *   The diagram source.
   * @param array $bindings
   *   Element bindings stored next to the source (text notations).
   *
   * @return array<int, array{element: string, entity: string, label: string}>
   *   One entry per linked element; "entity" is an entity:<type>/<id> URI.
   */
  public function extractLinks(string $source, array $bindings = []): array;

  /**
   * Extracts element names and documentation for search.
   */
  public function extractSearchText(string $source): string;

  /**
   * Extra settings handed to the editor and preview libraries.
   */
  public function clientSettings(DiagramInterface $diagram): array;

}
