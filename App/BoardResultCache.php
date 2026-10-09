<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores complete sprint results by board and sprint. */
class BoardResultCache {

  private const FILE = 'board-results.json';

  /** Return a cached result, including one with no issues. */
  public static function load(string $boardId, string $sprintId): ?array {
    $cache = AppData::loadJson(self::FILE);
    $result = $cache[$boardId][$sprintId] ?? null;
    if (!is_array($result) || !is_array($result['configuration'] ?? null) || !is_array($result['issues'] ?? null)) {
      return null;
    }
    return $result;
  }

  public static function has(string $boardId, string $sprintId): bool {
    return self::load($boardId, $sprintId) !== null;
  }

  public static function save(string $boardId, string $sprintId, array $result): bool {
    $cache = AppData::loadJson(self::FILE);
    $cache[$boardId][$sprintId] = $result;
    return AppData::saveJson(self::FILE, $cache);
  }

  public static function clear(): bool {
    return AppData::saveJson(self::FILE, []);
  }

}
