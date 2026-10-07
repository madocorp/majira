<?php

namespace MAJIRA\App;

/** Builds generated and editor JQL from staged filters and project or sprint scope. */
class JqlBuilder {

  public const NO_SPRINTS = '__majira_no_sprint__';
  public const ASSIGNEE_ME = '__majira_current_user__';
  public const ASSIGNEE_UNASSIGNED = '__majira_unassigned__';

  public function current(): string {
    $filters = FilterState::load();
    $customFilter = FilterState::customFilterByName((string)$filters['selectedCustomFilter'], $filters);
    if ($customFilter !== null) {
      return trim((string)$customFilter['jql']);
    }
    if ((string)$filters['customJql'] !== '') {
      return trim((string)$filters['customJql']);
    }
    return $this->generated($filters);
  }

  public function forEditor(array $filters): string {
    if ((string)$filters['customJql'] !== '') {
      return (string)$filters['customJql'];
    }
    $customFilter = FilterState::customFilterByName((string)$filters['selectedCustomFilter'], $filters);
    return $customFilter === null ? $this->generatedForEditor($filters) : (string)$customFilter['jql'];
  }

  public function matchesGenerated(string $jql, array $filters): bool {
    return $jql === $this->generated($filters) || $jql === $this->generatedForEditor($filters);
  }

  public function orderByPositions(array $filters): array {
    $positions = [];
    foreach (FilterState::normalizeOrderBy($filters['orderBy'] ?? []) as $index => $order) {
      $field = (string)($order['field'] ?? '');
      $direction = (string)($order['direction'] ?? '');
      if (isset(FilterState::ORDER_FIELDS[$field]) && in_array($direction, ['ASC', 'DESC'], true)) {
        $positions[$field] = [
          'position' => $index + 1,
          'direction' => $direction,
        ];
      }
    }
    return $positions;
  }

  /** Build a query from staged filter choices, ignoring a prior saved or raw query. */
  public function generatedFor(array $filters): string {
    return $this->generated(FilterState::normalize($filters));
  }

  private function generated(array $filters): string {
    [$clauses, $order] = $this->generatedParts($filters);
    $jql = implode(' AND ', $clauses);
    return $jql . ($order === '' ? '' : ($jql === '' ? '' : ' ') . 'ORDER BY ' . $order);
  }

  private function generatedForEditor(array $filters): string {
    [$clauses, $order] = $this->generatedParts($filters);
    $jql = implode(" AND\n", $clauses);
    return $jql . ($order === '' ? '' : ($jql === '' ? '' : "\n") . 'ORDER BY ' . $order);
  }

  private function generatedParts(array $filters): array {
    $settings = Settings::load();
    $clauses = [];
    $projectKey = trim((string)($settings['projectKey'] ?? ''));
    if ($projectKey !== '') {
      $clauses[] = 'project = ' . $this->jqlString($projectKey);
    }
    foreach (['status' => 'status', 'type' => 'issuetype', 'priority' => 'priority'] as $group => $field) {
      if ($filters[$group] !== []) {
        $clauses[] = $field . ' in (' . implode(', ', array_map(fn(string $value): string => $this->jqlString($value), $filters[$group])) . ')';
      }
    }
    if ($filters['assignee'] !== []) {
      $clauses[] = $this->assigneeJql($filters['assignee']);
    }
    foreach (['updated' => 'updated', 'created' => 'created'] as $group => $field) {
      if ((string)$filters[$group] !== '') {
        $clauses[] = $field . ' >= ' . (string)$filters[$group];
      }
    }
    $text = $this->textSearchJql($filters['search']);
    if ($text !== '') {
      $clauses[] = $text;
    }
    $sprint = trim((string)($settings['sprintId'] ?? ''));
    if ($sprint === self::NO_SPRINTS) {
      $clauses[] = 'sprint is EMPTY';
    } else if ($sprint !== '' && ctype_digit($sprint)) {
      $clauses[] = 'sprint = ' . $sprint;
    }
    return [$clauses, $this->orderByJql($filters['orderBy'])];
  }

  private function assigneeJql(array $values): string {
    $parts = [];
    $names = [];
    foreach ($values as $value) {
      if ($value === self::ASSIGNEE_ME) {
        $parts[] = 'assignee = currentUser()';
      } else if ($value === self::ASSIGNEE_UNASSIGNED) {
        $parts[] = 'assignee is EMPTY';
      } else {
        $names[] = $value;
      }
    }
    if ($names !== []) {
      $parts[] = count($names) === 1
        ? 'assignee = ' . $this->jqlString($names[0])
        : 'assignee in (' . implode(', ', array_map(fn(string $value): string => $this->jqlString($value), $names)) . ')';
    }
    return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
  }

  private function textSearchJql(array $search): string {
    $text = trim((string)($search['text'] ?? ''));
    if ($text === '') {
      return '';
    }
    $value = $this->jqlString(!empty($search['exact']) ? '"' . $text . '"' : $text);
    $parts = [];
    foreach (['summary' => 'summary', 'description' => 'description', 'comments' => 'comment'] as $key => $field) {
      if (!empty($search[$key])) {
        $parts[] = $field . ' ~ ' . $value;
      }
    }
    return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
  }

  private function orderByJql(array $orderBy): string {
    $parts = [];
    foreach (FilterState::normalizeOrderBy($orderBy) as $order) {
      $parts[] = $this->jqlOrderField((string)$order['field']) . ' ' . (string)$order['direction'];
    }
    return implode(', ', $parts);
  }

  private function jqlOrderField(string $field): string {
    return $field === 'type' ? 'issuetype' : $field;
  }

  private function jqlString(string $value): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
  }

}
