<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores the last loaded Jira ticket result set. */
class TicketCache {

  private const FILE = 'tickets.json';

  public static function load(): array {
    return AppData::loadJson(self::FILE);
  }

  public static function save(array $state, array $issues, array $meta = []): bool {
    return AppData::saveJson(self::FILE, [
      'state' => $state,
      'issues' => array_values($issues),
      'meta' => $meta,
    ]);
  }

  public static function clear(): void {
    $file = AppData::file(self::FILE);
    if (is_file($file)) {
      unlink($file);
    }
  }

}
