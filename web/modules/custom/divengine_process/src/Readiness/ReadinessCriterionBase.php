<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Readiness;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\divengine_process\DevelopmentProcess;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\TraceabilityGaps;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for readiness criteria, with helpers to read a Development.
 */
abstract class ReadinessCriterionBase extends PluginBase implements ReadinessCriterionInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DevelopmentProcess $process,
    protected TraceabilityGaps $gaps,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('divengine_process.development'),
      $container->get('divengine_process.gaps'),
    );
  }

  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * The moderation state of an artifact's effective revision.
   */
  protected function state(ContentEntityInterface $artifact): string {
    return (string) $artifact->get('moderation_state')->value;
  }

  /**
   * Non-deprecated artifacts of a type in the Development.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  protected function current(DevelopmentInterface $development, string $bundle): array {
    return array_filter($this->gaps->artifacts($development, $bundle), fn ($a) => $this->state($a) !== 'deprecated');
  }

  /**
   * Required element lines of a type that apply to the evaluation.
   *
   * With a milestone in force (the line's own, else the evaluated one),
   * only lines for every milestone or for that milestone apply.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function requiredElements(DevelopmentInterface $development, string $element_type, array $line, ?string $milestone_type): array {
    $milestone = ($line['milestone_type'] ?? '') ?: $milestone_type;
    return array_values(array_filter(
      $this->process->policyLines($development, $element_type, ['required']),
      fn (array $element) => !$milestone || ($element['milestone_type'] ?? '') === '' || $element['milestone_type'] === $milestone,
    ));
  }

  /**
   * One reason per artifact still in one of the given states.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $artifacts
   * @param string[] $pending_states
   * @param callable $reason
   *   Builds the sentence from the record's name ("REQ-0001 Title").
   *
   * @return string[]
   */
  protected function pending(array $artifacts, array $pending_states, callable $reason): array {
    $reasons = [];
    foreach ($artifacts as $artifact) {
      if (in_array($this->state($artifact), $pending_states, TRUE)) {
        $reasons[] = (string) $reason(trim($artifact->get('artifact_key')->value . ' ' . $artifact->label()));
      }
    }
    return $reasons;
  }

}
