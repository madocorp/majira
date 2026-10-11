<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Adf;

/** Defines the shared list and ticket fields, their Jira IDs, and display values. */
final class FieldConfiguration {

  public const DEFAULT_FIELDS = ['key', 'summary', 'status', 'type', 'priority', 'assignee', 'reporter', 'created', 'updated', 'duedate', 'labels'];

  private const BUILTINS = [
    'key' => 'Key', 'summary' => 'Summary', 'status' => 'Status', 'type' => 'Type',
    'priority' => 'Priority', 'assignee' => 'Assignee', 'reporter' => 'Reporter',
    'created' => 'Created', 'updated' => 'Updated', 'duedate' => 'Due date',
    'labels' => 'Labels', 'project' => 'Project', 'parent.key' => 'Parent key',
    'parent.summary' => 'Parent summary',
  ];

  /** Return selected IDs in saved display order, falling back to the shared defaults. */
  public static function selected(?array $settings = null): array {
    $settings ??= Settings::load();
    $fields = is_array($settings['fields'] ?? null) ? $settings['fields'] : self::DEFAULT_FIELDS;
    $ids = [];
    foreach ($fields as $field) {
      if (is_string($field) && self::validId($field) && !in_array($field, $ids, true)) {
        $ids[] = $field;
      }
    }
    return $ids === [] ? self::DEFAULT_FIELDS : $ids;
  }

  /** Merge built-in fields with the cached Jira field catalog. */
  public static function options(?array $settings = null): array {
    $settings ??= Settings::load();
    $options = self::BUILTINS;
    foreach (self::normalizeCatalog((array)($settings['fieldOptions'] ?? [])) as $field) {
      $id = self::displayId($field['id']);
      if (!isset($options[$id])) {
        $options[$id] = $field['name'];
      }
    }
    foreach (self::selected($settings) as $id) {
      $options[$id] ??= $id;
    }
    return $options;
  }

  /** Keep only printable Jira field identifiers and names from the API. */
  public static function normalizeCatalog(array $fields): array {
    $result = [];
    foreach ($fields as $field) {
      if (!is_array($field)) {
        continue;
      }
      $id = (string)($field['id'] ?? '');
      if (!self::validId($id)) {
        continue;
      }
      $name = trim((string)($field['name'] ?? $id));
      $name = mb_check_encoding($name, 'UTF-8')
        ? (preg_replace('/[\x00-\x1f\x7f]/u', ' ', $name) ?: $id) : $id;
      $result[$id] = ['id' => $id, 'name' => $name];
    }
    uasort($result, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
    return array_values($result);
  }

  /** Display the conventional Type name for Jira's issuetype field. */
  public static function displayId(string $jiraId): string {
    return $jiraId === 'issuetype' ? 'type' : $jiraId;
  }

  /** Map a display field to the Jira field requested from search or issue APIs. */
  public static function jiraId(string $id): ?string {
    return match ($id) {
      'key' => null,
      'type' => 'issuetype',
      'parent.key', 'parent.summary' => 'parent',
      default => $id,
    };
  }

  /** Add selected Jira fields to a request while retaining its required base fields. */
  public static function requestedFields(array $base, ?array $settings = null): array {
    foreach (self::selected($settings) as $id) {
      $jiraId = self::jiraId($id);
      if ($jiraId !== null && !in_array($jiraId, $base, true)) {
        $base[] = $jiraId;
      }
    }
    return $base;
  }

  /** Return the field's configured heading. */
  public static function label(string $id, ?array $settings = null): string {
    return self::options($settings)[$id] ?? $id;
  }

  /** Format scalar, option, user, and array values for a compact card or cell. */
  public static function value(array $issue, string $id): string {
    $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
    $value = match ($id) {
      'key' => (string)($issue['key'] ?? ''),
      'parent.key' => (string)($fields['parent']['key'] ?? ''),
      'parent.summary' => (string)($fields['parent']['fields']['summary'] ?? ''),
      'description' => Adf::toText($fields['description'] ?? null),
      default => self::format($fields[self::jiraId($id)] ?? null),
    };
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

  /** Format nested Jira field values without exposing array syntax in the UI. */
  private static function format(mixed $value): string {
    if ($value === null || $value === false) {
      return '';
    }
    if (is_scalar($value)) {
      return (string)$value;
    }
    if (is_array($value)) {
      foreach (['displayName', 'name', 'value', 'key', 'id'] as $key) {
        if (isset($value[$key]) && is_scalar($value[$key])) {
          return (string)$value[$key];
        }
      }
      return implode(', ', array_filter(array_map(self::format(...), $value), static fn(string $item): bool => $item !== ''));
    }
    return '';
  }

  /** Reject IDs that could break layout identifiers or Jira requests. */
  private static function validId(string $id): bool {
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $id) === 1;
  }

}
