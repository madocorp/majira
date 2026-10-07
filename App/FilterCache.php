<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores Jira filter options separately from connection settings. */
class FilterCache {

  private const FILE = 'filter-options.json';

  public static function load(string $projectKey): array {
    $cache = AppData::loadJson(self::FILE);
    if (is_array($cache[$projectKey] ?? null)) {
      return self::normalize($cache[$projectKey]);
    }
    // Older caches included a board ID even though these choices are project scoped.
    foreach ([$projectKey . '|', ...array_keys($cache)] as $key) {
      if (str_starts_with((string)$key, $projectKey . '|') && is_array($cache[$key] ?? null) && !empty($cache[$key]['_loaded'])) {
        return self::normalize($cache[$key]);
      }
    }
    return self::normalize([]);
  }

  public static function save(string $projectKey, array $options): bool {
    $cache = AppData::loadJson(self::FILE);
    $cache[$projectKey] = self::normalize($options);
    return AppData::saveJson(self::FILE, $cache);
  }

  public static function clear(): bool {
    return AppData::saveJson(self::FILE, []);
  }

  private static function normalize(array $options): array {
    return [
      '_loaded' => !empty($options['_loaded']),
      'priorityScope' => in_array($options['priorityScope'] ?? '', ['project', 'global'], true) ? $options['priorityScope'] : '',
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
