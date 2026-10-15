<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\divengine_modeler\ModelerNotationInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A diagram of any notation.
 *
 * The source is the canonical document; the preview SVG is derived from it in
 * the browser and is only trusted when its hash matches the source.
 */
interface DiagramInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, RevisionLogInterface {

  /**
   * The notation plugin of the diagram's type.
   */
  public function getNotation(): ModelerNotationInterface;

  public function getSource(): string;

  public function setSource(string $source): static;

  /**
   * Element bindings of text notations: semantic key => {entity, label}.
   */
  public function getBindings(): array;

  public function setBindings(array $bindings): static;

  public function getPreviewSvg(): string;

  public function getPreviewHash(): string;

  /**
   * Stores a preview rendered from the current source.
   */
  public function setPreview(string $svg): static;

  /**
   * Whether a preview exists and was rendered from the current source.
   */
  public function hasFreshPreview(): bool;

  /**
   * The hash identifying a source.
   */
  public static function hashSource(string $source): string;

}
