<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores recently opened ticket keys and titles. */
class TicketHistory {

  private const FILE = 'ticket-history.json';
  private const LIMIT = 50;

  /** Return recently opened tickets in reverse order. */
  public static function load(): array {
    return self::normalize(AppData::loadJson(self::FILE));
  }

  /** Record a ticket only when full details have been loaded for display. */
  public static function add(array $issue): bool {
    $key = trim((string)($issue['key'] ?? ''));
    $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
    if ($key === '' || !array_key_exists('description', $fields)) {
      return false;
    }
    return AppData::saveJson(self::FILE, self::normalize(array_merge([[
      'key' => $key,
      'title' => trim((string)($fields['summary'] ?? '')),
    ]], self::load())));
  }

  /** Forget recently opened tickets when the Jira account changes. */
  public static function clear(): bool {
    return AppData::saveJson(self::FILE, []);
  }

  private static function normalize(array $items): array {
    $normalized = [];
    $seen = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $key = strtoupper(trim((string)($item['key'] ?? '')));
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $normalized[] = [
        'key' => $key,
        'title' => trim((string)($item['title'] ?? '')),
      ];
      $seen[$key] = true;
      if (count($normalized) >= self::LIMIT) {
        break;
      }
    }
    return $normalized;
  }

}
