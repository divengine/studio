<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use Drupal\divengine_modeler\ModelerNotationBase;
use Drupal\divengine_modeler\SvgSafety;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ValidDiagramModel constraint.
 */
class ValidDiagramModelConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected SvgSafety $svgSafety,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_modeler.svg_safety'));
  }

  public function validate(mixed $diagram, Constraint $constraint): void {
    if (!$diagram instanceof DiagramInterface) {
      return;
    }
    $source = $diagram->getSource();
    // An empty source is replaced by the notation template on save.
    if (trim($source) !== '') {
      foreach ($diagram->getNotation()->validateSource($source) as $error) {
        $this->context->buildViolation($error)->atPath('source')->addViolation();
      }
    }
    $svg = $diagram->getPreviewSvg();
    if ($svg !== '') {
      foreach ($this->svgSafety->check($svg) as $error) {
        $this->context->buildViolation($error)->atPath('preview_svg')->addViolation();
      }
    }
    foreach ($this->checkBindings($diagram->getBindings(), $constraint) as $error) {
      $this->context->buildViolation($error)->atPath('element_bindings')->addViolation();
    }
  }

  /**
   * @return string[]
   */
  protected function checkBindings(array $bindings, ValidDiagramModelConstraint $constraint): array {
    if (count($bindings) > $constraint::MAX_BINDINGS) {
      return [sprintf('A diagram can bind at most %d elements.', $constraint::MAX_BINDINGS)];
    }
    foreach ($bindings as $key => $binding) {
      if (!is_string($key) || $key === '' || mb_strlen($key) > 255) {
        return ['Element binding keys must be non-empty strings of at most 255 characters.'];
      }
      if (!is_array($binding) || !is_string($binding['entity'] ?? NULL) || !ModelerNotationBase::isEntityUri($binding['entity'])) {
        return [sprintf('The binding of "%s" must point to an entity:<type>/<id> URI.', mb_substr($key, 0, 60))];
      }
      if (isset($binding['label']) && (!is_string($binding['label']) || mb_strlen($binding['label']) > 255)) {
        return [sprintf('The label of the binding of "%s" must be a string of at most 255 characters.', mb_substr($key, 0, 60))];
      }
      if (array_diff(array_keys($binding), ['entity', 'label'])) {
        return [sprintf('The binding of "%s" has unknown properties.', mb_substr($key, 0, 60))];
      }
    }
    return [];
  }

}
