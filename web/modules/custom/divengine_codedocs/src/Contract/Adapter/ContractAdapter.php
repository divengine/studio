<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Contract\Adapter;

use Drupal\divengine_codedocs\Contract\ContractException;

/**
 * The common model itself: what an extractor written for Studio sends.
 *
 * {"contract": "divengine.codedocs", "contract_version": "1.0",
 *  "project": "billing-backend", "language": "php", "producer": "…",
 *  "commit": "…", "branch": "main", "units": [unit, …]}
 *
 * Symbols may come nested ("members") or flat (with "parent").
 */
class ContractAdapter implements CodeDocsAdapterInterface {

  const VERSION = '1.0';

  public function id(): string {
    return 'contract';
  }

  public function label(): string {
    return 'Divengine contract (JSON)';
  }

  public function applies(array $document): bool {
    return ($document['contract'] ?? NULL) === 'divengine.codedocs';
  }

  public function chunkKeys(): array {
    return ['units'];
  }

  public function meta(array $header): array {
    if (($header['contract_version'] ?? NULL) !== static::VERSION) {
      throw new ContractException(sprintf('Unsupported contract_version %s (expected "%s").', json_encode($header['contract_version'] ?? NULL), static::VERSION));
    }
    return [
      'project' => (string) ($header['project'] ?? ''),
      'language' => (string) ($header['language'] ?? ''),
      'producer' => (string) ($header['producer'] ?? ''),
      'commit' => (string) ($header['commit'] ?? ''),
      'branch' => (string) ($header['branch'] ?? ''),
      'captured_at' => $header['captured_at'] ?? NULL,
    ];
  }

  public function units(array $chunk, array $header): array {
    $units = [];
    foreach ($chunk['units'] ?? [] as $unit) {
      if (!is_array($unit) || trim((string) ($unit['key'] ?? '')) === '') {
        throw new ContractException('Every unit needs a key.');
      }
      $symbols = [];
      foreach ($unit['symbols'] ?? [] as $symbol) {
        $this->flatten($symbol, $unit['parent_symbol'] ?? NULL, $symbols);
      }
      $unit['symbols'] = $symbols;
      $unit['language'] ??= $header['language'] ?? '';
      $units[] = $unit;
    }
    return $units;
  }

  /**
   * Nested members become flat symbols that name their parent.
   */
  protected function flatten(array $symbol, ?string $parent, array &$out): void {
    if (trim((string) ($symbol['key'] ?? '')) === '') {
      throw new ContractException(sprintf('Symbol %s has no key.', $symbol['name'] ?? '?'));
    }
    $members = $symbol['members'] ?? [];
    unset($symbol['members']);
    $symbol['parent'] ??= $parent;
    $out[] = $symbol;
    foreach ($members as $member) {
      $this->flatten($member, $symbol['key'], $out);
    }
  }

}
