<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Contract\Adapter;

use Drupal\divengine_database\Contract\ContractException;

/**
 * The normalized contract itself, as JSON: what any analyzer can send.
 */
class JsonContractAdapter implements ContractAdapterInterface {

  public function id(): string {
    return 'contract';
  }

  public function label(): string {
    return 'Divengine contract (JSON)';
  }

  public function applies(string $content, string $filename = ''): bool {
    return str_starts_with(ltrim($content), '{');
  }

  public function toContract(string $content): array {
    try {
      $payload = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new ContractException('The file is not valid JSON: ' . $e->getMessage());
    }
    if (!is_array($payload) || array_is_list($payload)) {
      throw new ContractException('The contract must be a JSON object.');
    }
    return $payload;
  }

}
