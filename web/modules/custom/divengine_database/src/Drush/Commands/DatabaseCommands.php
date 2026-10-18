<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_database\Contract\AdapterManager;
use Drupal\divengine_database\Contract\ContractException;
use Drupal\divengine_database\SnapshotImporter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Imports snapshots from the command line (CI, cron, after a migration).
 */
final class DatabaseCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'divengine_database.adapters')]
    protected AdapterManager $adapters,
    #[Autowire(service: 'divengine_database.importer')]
    protected SnapshotImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports a snapshot: the Divengine contract (JSON) or SchemaSpy's XML.
   */
  #[CLI\Command(name: 'divengine:database-import', aliases: ['ddi'])]
  #[CLI\Argument(name: 'file', description: 'The file an analyzer produced; "-" reads standard input.')]
  #[CLI\Option(name: 'database', description: 'Database code (DB-0001) to import into; by default the one whose identifier is the snapshot\'s database code.')]
  #[CLI\Option(name: 'development', description: 'Development code (DEV-0001): register the database there when it does not exist yet.')]
  #[CLI\Option(name: 'format', description: 'auto, contract or schemaspy.')]
  #[CLI\Option(name: 'revision', description: 'The revision the schema was captured at (Git SHA, release, migration).')]
  #[CLI\Option(name: 'force', description: 'Import even when the schema equals the latest snapshot.')]
  #[CLI\Usage(name: 'drush ddi target/schemaspy/billing.billing.xml --revision=$(git rev-parse HEAD)', description: 'Import a SchemaSpy dump at the current commit.')]
  public function import(string $file, array $options = ['database' => NULL, 'development' => NULL, 'format' => 'auto', 'revision' => NULL, 'force' => FALSE]): int {
    $content = $file === '-' ? stream_get_contents(STDIN) : (is_readable($file) ? file_get_contents($file) : FALSE);
    if ($content === FALSE || $content === '') {
      $this->logger()->error(sprintf('Cannot read %s.', $file));
      return self::EXIT_FAILURE;
    }
    try {
      $payload = $this->adapters->toContract($content, (string) $options['format'], $file);
      $this->importer->validate($payload);
      $database = $this->database($payload, $options);
      $result = $this->importer->import($payload, $database, ['revision' => $options['revision'], 'force' => (bool) $options['force']]);
    }
    catch (ContractException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(sprintf('[%s] %s', $result['status'], $result['message']));
    return self::EXIT_SUCCESS;
  }

  /**
   * The target database: the one named, else by identifier, else a new one
   * registered in the Development given.
   */
  protected function database(array $payload, array $options): ?object {
    $storage = $this->entityTypeManager->getStorage('schema_database');
    if ($options['database']) {
      $found = $storage->loadByProperties(['code' => mb_strtoupper((string) $options['database'])]);
      return reset($found) ?: throw new ContractException(sprintf('There is no database %s.', $options['database']));
    }
    $identifier = (string) ($payload['database']['code'] ?? '');
    if ($storage->loadByProperties(['identifier' => $identifier]) || !$options['development']) {
      return NULL;
    }
    $developments = $this->entityTypeManager->getStorage('development')->loadByProperties(['code' => mb_strtoupper((string) $options['development'])]);
    $development = reset($developments) ?: throw new ContractException(sprintf('There is no Development %s.', $options['development']));
    $database = $storage->create([
      'development' => $development->id(),
      'name' => (string) ($payload['database']['name'] ?? '') ?: $identifier,
      'identifier' => $identifier,
      'engine' => (string) ($payload['database']['engine'] ?? ''),
    ]);
    // The command line is trusted: the Development was resolved above.
    $violations = $database->validate()->filterByFields(['development', 'uid']);
    if ($violations->count()) {
      throw new ContractException((string) $violations->get(0)->getMessage());
    }
    $database->save();
    $this->logger()->notice(sprintf('Registered %s in %s.', $database->label(), $development->label()));
    return $database;
  }

}
