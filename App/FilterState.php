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
    $normalized['customJqlEdited'] = (bool)($filters['customJqlEdited'] ?? false);
    $legacyJql = trim((string)($filters['customJql'] ?? '')) !== '' || !empty($filters['customJqlEdited']);
    $normalized['mode'] = ($filters['mode'] ?? ($legacyJql ? 'jql' : 'builder')) === 'jql' ? 'jql' : 'builder';
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
    if ($normalized['customJql'] !== '' || $normalized['customJqlEdited']) {
      $normalized['selectedCustomFilter'] = '';
    }
    if ($normalized['selectedCustomFilter'] !== '') {
      $selected = self::customFilterByName($normalized['selectedCustomFilter'], $normalized);
      if ($selected === null) {
        $normalized['selectedCustomFilter'] = '';
      } else {
        $normalized['mode'] = $selected['mode'];
      }
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
      'customJqlEdited' => false,
      'mode' => 'builder',
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
      $key = mb_strtolower($name);
      if ($name === '' || isset($names[$key])) {
        continue;
      }
      $names[$key] = true;
      $row = ['name' => $name, 'jql' => $jql];
      // Existing saved queries may contain hand edits; preserve them as JQL filters.
      $legacyMode = $jql === '' && isset($filter['form']) ? 'builder' : 'jql';
      $row['mode'] = ($filter['mode'] ?? $legacyMode) === 'jql' ? 'jql' : 'builder';
      if (is_array($filter['form'] ?? null)) {
        $row['form'] = self::formValues($filter['form']);
      }
      if (is_array($filter['scope'] ?? null)) {
        $row['scope'] = self::scopeValues($filter['scope']);
      }
      $normalized[] = $row;
    }
    return $normalized;
  }

  /** Keep only the editable fields in a saved form snapshot. */
  public static function formValues(array $filters): array {
    $defaults = self::defaults();
    $form = [];
    foreach (['assignee', 'status', 'type', 'priority'] as $group) {
      $form[$group] = self::stringList($filters[$group] ?? []);
    }
    foreach (['updated', 'updatedTo', 'created', 'createdTo'] as $field) {
      $date = trim((string)($filters[$field] ?? ''));
      $form[$field] = self::validDate($date) ? $date : '';
    }
    $search = is_array($filters['search'] ?? null) ? $filters['search'] : [];
    $form['search'] = array_replace($defaults['search'], array_intersect_key($search, $defaults['search']));
    $form['search']['text'] = trim((string)$form['search']['text']);
    return $form;
  }

  public static function scopeValues(array $settings): array {
    return [
      'projectKey' => trim((string)($settings['projectKey'] ?? '')),
      'boardId' => trim((string)($settings['boardId'] ?? '')),
      'sprintId' => trim((string)($settings['sprintId'] ?? '')),
    ];
  }

  /** Reset the selected filter while retaining its name, mode, and other saved filters. */
  public static function clearCurrent(array $filters): array {
    $filters = self::normalize($filters);
    $form = self::formValues(self::defaults());
    $filters = array_replace($filters, $form);
    $filters['orderBy'] = [];
    $filters['customJql'] = '';
    $name = $filters['selectedCustomFilter'];
    $filters['customJqlEdited'] = $name === '' && $filters['mode'] === 'jql';
    if ($name !== '') {
      foreach ($filters['customFilters'] as &$row) {
        if ($row['name'] === $name) {
          $row['jql'] = '';
          $row['form'] = $form;
          $row['scope'] = self::scopeValues([]);
          break;
        }
      }
      unset($row);
    }
    return $filters;
  }

  /** Remove one saved filter and select its next neighbor, or the previous last row. */
  public static function removeSavedFilter(array $filters, string $name): array {
    $filters = self::normalize($filters);
    foreach ($filters['customFilters'] as $index => $row) {
      if ($row['name'] !== $name) {
        continue;
      }
      array_splice($filters['customFilters'], $index, 1);
      $remaining = $filters['customFilters'];
      $next = $remaining === [] ? null : $remaining[min($index, count($remaining) - 1)];
      $filters['selectedCustomFilter'] = $next['name'] ?? '';
      $filters['mode'] = $next['mode'] ?? 'builder';
      $filters['customJql'] = '';
      $filters['customJqlEdited'] = false;
      break;
    }
    return $filters;
  }

  /** Reorder saved rows while keeping each row's query and form attached to its name. */
  public static function reorderSavedFilters(array $filters, array $names): array {
    $filters = self::normalize($filters);
    $rows = [];
    foreach ($filters['customFilters'] as $row) {
      $rows[$row['name']] = $row;
    }
    if (count($names) !== count($rows) || count(array_unique($names)) !== count($names)) {
      return $filters;
    }
    $ordered = [];
    foreach ($names as $name) {
      if (!is_string($name) || !isset($rows[$name])) {
        return $filters;
      }
      $ordered[] = $rows[$name];
    }
    $filters['customFilters'] = $ordered;
    return $filters;
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
