<?php

namespace MAJIRA\App;

/** Loads, saves, and normalizes staged filter settings. */
class FilterState {

  public const ORDER_FIELDS = [
    'key' => 'Key',
    'summary' => 'Summary',
    'status' => 'Status',
    'assignee' => 'Assignee',
    'reporter' => 'Reporter',
    'priority' => 'Priority',
    'issuetype' => 'Type',
    'created' => 'Created',
    'updated' => 'Updated',
  ];

  public static function load(): array {
    return self::normalize(Settings::load()['filters'] ?? []);
  }

  public static function save(array $filters): void {
    $settings = Settings::load();
    $settings['filters'] = self::normalize($filters);
    Settings::save($settings);
  }

  public static function normalize(mixed $filters): array {
    $filters = is_array($filters) ? $filters : [];
    $normalized = self::defaults();
    foreach (['assignee', 'status', 'type', 'priority'] as $group) {
      $normalized[$group] = self::stringList($filters[$group] ?? []);
    }
    foreach (['customJql', 'lastJql', 'selectedCustomFilter'] as $key) {
      $normalized[$key] = trim((string)($filters[$key] ?? ''));
    }
    foreach (['updated', 'created'] as $field) {
      $date = trim((string)($filters[$field] ?? ''));
      $to = trim((string)($filters[$field . 'To'] ?? ''));
      $normalized[$field] = self::validDate($date) ? $date : '';
      $normalized[$field . 'To'] = self::validDate($to) ? $to : '';
      // Migrate date modes from the previous filter layout to optional bounds.
      switch ($filters[$field . 'Mode'] ?? null) {
        case 'any':
          $normalized[$field] = $normalized[$field . 'To'] = '';
          break;
        case 'since':
          $normalized[$field . 'To'] = '';
          break;
        case 'until':
          $normalized[$field . 'To'] = $normalized[$field];
          $normalized[$field] = '';
          break;
      }
    }
    if (!array_key_exists('lastJql', $filters)) {
      $normalized['lastJql'] = $normalized['customJql'];
    }
    $search = is_array($filters['search'] ?? null) ? $filters['search'] : [];
    $normalized['search'] = [
      'text' => trim((string)($search['text'] ?? '')),
      'summary' => (bool)($search['summary'] ?? true),
      'description' => (bool)($search['description'] ?? true),
      'comments' => (bool)($search['comments'] ?? true),
      'exact' => (bool)($search['exact'] ?? true),
    ];
    $normalized['orderBy'] = self::normalizeOrderBy($filters['orderBy'] ?? []);
    $normalized['customFilters'] = self::normalizeCustomFilters($filters['customFilters'] ?? []);
    if ($normalized['customJql'] !== '') {
      $normalized['selectedCustomFilter'] = '';
    }
    return $normalized;
  }

  public static function defaults(): array {
    return [
      'assignee' => [],
      'status' => [],
      'type' => [],
      'priority' => [],
      'updated' => '',
      'updatedTo' => '',
      'created' => '',
      'createdTo' => '',
      'search' => self::defaultSearch(),
      'orderBy' => [],
      'customJql' => '',
      'lastJql' => '',
      'customFilters' => [],
      'selectedCustomFilter' => '',
    ];
  }

  /** Check an ISO calendar date before it enters a DateSelector or JQL. */
  public static function validDate(string $date): bool {
    return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) === 1
      && (int)$parts[1] >= 1
      && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
  }

  public static function defaultSearch(): array {
    return [
      'text' => '',
      'summary' => true,
      'description' => true,
      'comments' => true,
      'exact' => true,
    ];
  }

  public static function stringList(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $list = [];
    foreach ($values as $value) {
      $value = trim((string)$value);
      if ($value !== '' && !in_array($value, $list, true)) {
        $list[] = $value;
      }
    }
    return $list;
  }

  public static function normalizeOrderBy(mixed $orders): array {
    if (!is_array($orders)) {
      return [];
    }
    $normalized = [];
    foreach ($orders as $order) {
      if (!is_array($order)) {
        continue;
      }
      $field = (string)($order['field'] ?? '');
      $direction = strtoupper((string)($order['direction'] ?? ''));
      if (isset(self::ORDER_FIELDS[$field]) && in_array($direction, ['ASC', 'DESC'], true)) {
        $normalized[] = ['field' => $field, 'direction' => $direction];
      }
    }
    return $normalized;
  }

  public static function normalizeCustomFilters(mixed $filters): array {
    if (!is_array($filters)) {
      return [];
    }
    $normalized = [];
    $names = [];
    foreach ($filters as $filter) {
      if (!is_array($filter)) {
        continue;
      }
      $name = trim((string)($filter['name'] ?? ''));
      $jql = trim((string)($filter['jql'] ?? ''));
      if ($name === '' || $jql === '' || isset($names[$name])) {
        continue;
      }
      $names[$name] = true;
      $normalized[] = ['name' => $name, 'jql' => $jql];
    }
    return $normalized;
  }

  public static function customFilterByName(string $name, array $filters): ?array {
    foreach ($filters['customFilters'] as $filter) {
      if ((string)$filter['name'] === $name) {
        return $filter;
      }
    }
    return null;
  }

}
