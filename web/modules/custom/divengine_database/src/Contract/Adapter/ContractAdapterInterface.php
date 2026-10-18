<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Contract\Adapter;

/**
 * Turns what an analyzer produced into the normalized contract (v1).
 *
 * The contract is the seam between analyzers and Studio: an adapter is the
 * only place that knows an analyzer's own format. Register more as services
 * tagged "divengine_database.contract_adapter".
 */
interface ContractAdapterInterface {

  /**
   * Machine name, e.g. "schemaspy".
   */
  public function id(): string;

  /**
   * Human name, e.g. "SchemaSpy XML".
   */
  public function label(): string;

  /**
   * Whether this content looks like the adapter's format.
   */
  public function applies(string $content, string $filename = ''): bool;

  /**
   * The contract payload of the content.
   *
   * @throws \Drupal\divengine_database\Contract\ContractException
   */
  public function toContract(string $content): array;

}
