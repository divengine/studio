<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * The Studio building blocks the database pages are made of.
 */
trait PageElementsTrait {

  protected function card($label, string $value, string $tone = 'neutral', string $hint = ''): array {
    return ['#type' => 'component', '#component' => 'divengine_core:stat-card', '#props' => array_filter(['label' => (string) $label, 'value' => $value, 'tone' => $tone, 'hint' => $hint], fn ($v) => $v !== '')];
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL, $description = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => array_filter(['title' => (string) $title, 'count' => $count ? (string) $count : '', 'description' => (string) $description]),
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function badge($label, string $tone = 'neutral'): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => (string) $label, 'tone' => $tone]];
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

  protected function actions(array $buttons): ?array {
    return $buttons ? ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'buttons' => $buttons] : NULL;
  }

  protected function statusBar(string $code, array $facts, array $buttons = []): array {
    $facts = array_filter($facts, fn ($value) => $value !== NULL && $value !== '');
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => ['code' => $code, 'facts' => array_map(fn ($label, $value) => ['label' => (string) $label, 'value' => (string) $value], array_keys($facts), $facts)],
      '#slots' => array_filter(['actions' => $this->actions($buttons)]),
      '#weight' => -100,
    ];
  }

  protected function code(?string $value): array {
    return ['#markup' => $value === NULL || $value === '' ? '' : '<code>' . htmlspecialchars($value) . '</code>'];
  }

  protected static function severityTone(string $severity): string {
    return match ($severity) {
      'error' => 'danger',
      'warning' => 'warning',
      default => 'info',
    };
  }

  /**
   * A table of findings.
   */
  protected function findingTable(array $findings, int $sid, bool $with_table = TRUE): array {
    $rows = [];
    foreach ($findings as $finding) {
      $row = [
        ['data' => $this->badge($finding->severity, static::severityTone($finding->severity))],
        ['data' => ['#markup' => '<strong>' . htmlspecialchars($finding->title) . '</strong><br><small>' . htmlspecialchars((string) $finding->detail) . '</small>']],
        ['data' => $this->code($finding->rule_code)],
      ];
      if ($with_table) {
        array_splice($row, 1, 0, [['data' => $finding->table_id && $finding->table_name ? Link::fromTextAndUrl($finding->table_name, Url::fromRoute('divengine_database.table', ['schema_snapshot' => $sid, 'table' => $finding->table_id]))->toRenderable() : ['#markup' => '']]]);
      }
      $rows[] = $row;
    }
    $header = [$this->t('Severity'), $this->t('Finding'), $this->t('Rule')];
    if ($with_table) {
      array_splice($header, 1, 0, [$this->t('Table')]);
    }
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $this->t('No finding.')];
  }

}
