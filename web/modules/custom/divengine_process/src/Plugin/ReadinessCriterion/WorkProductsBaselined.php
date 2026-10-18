<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Every required work product definition has a baselined work product.
 *
 * Scoped to the milestone being evaluated through the element lines.
 */
#[ReadinessCriterion(
  id: 'work_products_baselined',
  label: new TranslatableMarkup('Required work products are baselined'),
)]
class WorkProductsBaselined extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $baselined = [];
    foreach ($this->gaps->artifacts($development, 'work_product') as $work_product) {
      if ($this->state($work_product) === 'published' && ($definition = $work_product->get('field_wp_definition')->target_id)) {
        $baselined[$definition] = TRUE;
      }
    }
    $reasons = [];
    foreach ($this->requiredElements($development, 'work_product_definition', $line, $milestone_type) as $element) {
      if (!isset($baselined[$element['target']])) {
        $definition = $this->entityTypeManager->getStorage('work_product_definition')->load($element['target']);
        $reasons[$element['target']] = (string) $this->t('No baselined @definition.', ['@definition' => $definition?->label() ?? $element['target']]);
      }
    }
    return ReadinessResult::fromReasons($reasons);
  }

}
