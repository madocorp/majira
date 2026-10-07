<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores Jira sprint options by board id separately from connection settings. */
class SprintCache {

  private const FILE = 'sprints.json';

  /** An empty fetched sprint list is still a cached result. */
  public static function has(string $boardId): bool {
    return array_key_exists($boardId, AppData::loadJson(self::FILE));
  }

  public static function load(string $boardId): array {
    $cache = AppData::loadJson(self::FILE);
    return self::normalize(is_array($cache[$boardId] ?? null) ? $cache[$boardId] : []);
  }

  public static function save(string $boardId, array $sprints): bool {
    $cache = AppData::loadJson(self::FILE);
    $cache[$boardId] = self::normalize($sprints);
    return AppData::saveJson(self::FILE, $cache);
  }

  public static function clear(): bool {
    return AppData::saveJson(self::FILE, []);
  }

  private static function normalize(array $sprints): array {
    $normalized = [];
    foreach ($sprints as $sprint) {
      if (!is_array($sprint)) {
        continue;
      }
      $id = trim((string)($sprint['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $normalized[] = [
        'id' => $id,
        'name' => trim((string)($sprint['name'] ?? $id)),
        'state' => trim((string)($sprint['state'] ?? '')),
      ];
    }
    usort($normalized, function(array $a, array $b): int {
      return self::stateOrder($a['state']) <=> self::stateOrder($b['state'])
        ?: strcasecmp($a['name'], $b['name'])
        ?: strcasecmp($a['id'], $b['id']);
    });
    return $normalized;
  }

  private static function stateOrder(string $state): int {
    return match (strtolower($state)) {
      'active' => 0,
      'future' => 1,
      'closed' => 2,
      default => 3,
    };
  }

}
