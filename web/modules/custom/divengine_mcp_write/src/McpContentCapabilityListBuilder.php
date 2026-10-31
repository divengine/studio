<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * The content bundles enabled for MCP and what clients may do with them.
 */
class McpContentCapabilityListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Content type'),
      'operations_enabled' => $this->t('Operations'),
      'fields' => $this->t('Writable fields'),
      'development' => $this->t('Development'),
      'state' => $this->t('State'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_mcp_write\Entity\McpContentCapability $entity */
    $problems = \Drupal::service('divengine_mcp_write.content_models')->capabilityProblems($entity);
    return [
      'label' => $entity->label() . ' (' . $entity->id() . ')',
      'operations_enabled' => implode(', ', $entity->getOperations()) ?: $this->t('none'),
      'fields' => implode(', ', $entity->getWritableFields()) ?: '-',
      'development' => $entity->getDevelopmentField() === '' ? '-' : ($entity->requiresDevelopment() ? $this->t('required') : $this->t('optional')),
      'state' => $problems ? $this->t('Refused: @problems', ['@problems' => implode(' ', $problems)]) : $this->t('In use'),
    ] + parent::buildRow($entity);
  }

  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No content type is enabled for MCP. Every operation stays off until one is added here.');
    return $build;
  }

}
