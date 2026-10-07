<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores Jira board options by project key separately from connection settings. */
class BoardCache {

  private const FILE = 'boards.json';

  public static function load(string $projectKey): array {
    $cache = AppData::loadJson(self::FILE);
    return self::normalize(is_array($cache[$projectKey] ?? null) ? $cache[$projectKey] : []);
  }

  public static function save(string $projectKey, array $boards): bool {
    $cache = AppData::loadJson(self::FILE);
    $cache[$projectKey] = self::normalize($boards);
    return AppData::saveJson(self::FILE, $cache);
  }

  public static function clear(): bool {
    return AppData::saveJson(self::FILE, []);
  }

  private static function normalize(array $boards): array {
    $normalized = [];
    foreach ($boards as $board) {
      if (!is_array($board)) {
        continue;
      }
      $id = trim((string)($board['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $normalized[] = [
        'id' => $id,
        'name' => trim((string)($board['name'] ?? $id)),
      ];
    }
    usort($normalized, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['id'], $b['id']));
    return $normalized;
  }

}
