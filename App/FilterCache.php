<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores Jira filter options separately from connection settings. */
class FilterCache {

  private const FILE = 'filter-options.json';

  public static function load(string $projectKey, string $boardId): array {
    $cache = AppData::loadJson(self::FILE);
    $key = self::key($projectKey, $boardId);
    return self::normalize(is_array($cache[$key] ?? null) ? $cache[$key] : []);
  }

  public static function save(string $projectKey, string $boardId, array $options): bool {
    $cache = AppData::loadJson(self::FILE);
    $cache[self::key($projectKey, $boardId)] = self::normalize($options);
    return AppData::saveJson(self::FILE, $cache);
  }

  public static function clear(): bool {
    return AppData::saveJson(self::FILE, []);
  }

  private static function key(string $projectKey, string $boardId): string {
    return trim($projectKey) . '|' . trim($boardId);
  }

  private static function normalize(array $options): array {
    return [
      '_loaded' => !empty($options['_loaded']),
      'assignee' => self::normalizeValues($options['assignee'] ?? []),
      'assigneeUsers' => self::normalizeUsers($options['assigneeUsers'] ?? []),
      'reporterUsers' => self::normalizeUsers($options['reporterUsers'] ?? []),
      'status' => self::normalizeValues($options['status'] ?? []),
      'type' => self::normalizeValues($options['type'] ?? []),
      'priority' => self::normalizeValues($options['priority'] ?? []),
    ];
  }

  private static function normalizeValues(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $normalized = [];
    foreach ($values as $value) {
      $value = trim((string)$value);
      if ($value !== '' && !in_array($value, $normalized, true)) {
        $normalized[] = $value;
      }
    }
    natcasesort($normalized);
    return array_values($normalized);
  }

  private static function normalizeUsers(mixed $users): array {
    if (!is_array($users)) {
      return [];
    }
    $normalized = [];
    $seen = [];
    foreach ($users as $user) {
      if (!is_array($user)) {
        continue;
      }
      $accountId = trim((string)($user['accountId'] ?? ''));
      if ($accountId === '' || isset($seen[$accountId])) {
        continue;
      }
      $normalized[] = [
        'accountId' => $accountId,
        'displayName' => trim((string)($user['displayName'] ?? $accountId)),
      ];
      $seen[$accountId] = true;
    }
    usort($normalized, fn(array $a, array $b): int => strcasecmp((string)$a['displayName'], (string)$b['displayName']));
    return array_values($normalized);
  }

}
