<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Stores Jira project options separately from connection settings. */
class ProjectCache {

  private const FILE = 'projects.json';

  public static function load(): array {
    return self::normalize(AppData::loadJson(self::FILE));
  }

  public static function save(array $projects): bool {
    return AppData::saveJson(self::FILE, self::normalize($projects));
  }

  public static function clear(): bool {
    return self::save([]);
  }

  private static function normalize(array $projects): array {
    $normalized = [];
    foreach ($projects as $project) {
      if (!is_array($project)) {
        continue;
      }
      $key = trim((string)($project['key'] ?? ''));
      if ($key === '') {
        continue;
      }
      $normalized[] = [
        'key' => $key,
        'name' => trim((string)($project['name'] ?? $key)),
      ];
    }
    usort($normalized, fn(array $a, array $b) => strcasecmp($a['key'], $b['key']) ?: strcasecmp($a['name'], $b['name']));
    return $normalized;
  }

}
