<?php

namespace MAJIRA\App;

/** Group sprint issues using the selected board's configured column order. */
class BoardView {

  public static function columns(array $configuration, array $issues): array {
    $columns = [];
    $statusColumns = [];
    foreach ($configuration['columnConfig']['columns'] ?? [] as $column) {
      if (!is_array($column)) {
        continue;
      }
      $index = count($columns);
      $columns[] = ['name' => (string)($column['name'] ?? 'Column'), 'issues' => []];
      foreach ($column['statuses'] ?? [] as $status) {
        $id = (string)($status['id'] ?? '');
        if ($id !== '' && !isset($statusColumns[$id])) {
          $statusColumns[$id] = $index;
        }
      }
    }
    foreach ($issues as $issue) {
      if (!is_array($issue)) {
        continue;
      }
      $status = $issue['fields']['status'] ?? [];
      $id = (string)($status['id'] ?? '');
      $index = $statusColumns[$id] ?? null;
      if ($index === null) {
        $name = (string)($status['name'] ?? 'Unmapped');
        $fallback = 'Unmapped: ' . $name;
        $index = array_search($fallback, array_column($columns, 'name'), true);
        if ($index === false) {
          $index = count($columns);
          $columns[] = ['name' => $fallback, 'issues' => []];
        }
      }
      $columns[$index]['issues'][] = $issue;
    }
    return $columns;
  }
}
