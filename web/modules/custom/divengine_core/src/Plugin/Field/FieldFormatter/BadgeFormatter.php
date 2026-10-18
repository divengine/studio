<?php

declare(strict_types=1);

namespace Drupal\divengine_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shows list values as badges, each value with its own tone.
 */
#[FieldFormatter(
  id: 'divengine_badge',
  label: new TranslatableMarkup('Badge'),
  field_types: ['list_string'],
)]
class BadgeFormatter extends FormatterBase {

  const TONES = ['neutral', 'info', 'success', 'warning', 'danger'];

  public static function defaultSettings(): array {
    return ['tones' => []] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $lines = [];
    foreach ($this->getSetting('tones') as $value => $tone) {
      $lines[] = "$value|$tone";
    }
    $element['tones'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tones'),
      '#description' => $this->t('One "value|tone" per line; tones: @tones. Other values are neutral.', ['@tones' => implode(', ', static::TONES)]),
      '#default_value' => implode("\n", $lines),
      '#element_validate' => [[static::class, 'validateTones']],
    ];
    return $element;
  }

  /**
   * Turns the "value|tone" lines into a map.
   */
  public static function validateTones(array $element, FormStateInterface $form_state): void {
    $tones = [];
    foreach (preg_split('/\R/', (string) $element['#value']) as $line) {
      [$value, $tone] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
      if ($value === '') {
        continue;
      }
      if (!in_array($tone, static::TONES, TRUE)) {
        $form_state->setError($element, t('Unknown tone %tone.', ['%tone' => $tone]));
        return;
      }
      $tones[$value] = $tone;
    }
    $form_state->setValueForElement($element, $tones);
  }

  public function settingsSummary(): array {
    $tones = $this->getSetting('tones');
    return [$tones
      ? $this->formatPlural(count($tones), '1 value with a tone', '@count values with a tone')
      : $this->t('All values neutral')];
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $tones = $this->getSetting('tones');
    foreach ($items as $delta => $item) {
      $value = (string) $item->value;
      $options = $item->getPossibleOptions();
      $elements[$delta] = [
        '#type' => 'component',
        '#component' => 'divengine_core:badge',
        '#props' => [
          'label' => (string) ($options[$value] ?? $value),
          'tone' => $tones[$value] ?? 'neutral',
        ],
      ];
    }
    return $elements;
  }

}
