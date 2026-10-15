<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Adf;

/** Converts Jira edit metadata and issue values into Majira editor models and update payloads. */
class TicketFieldEditModel {

  private const ISSUE_KEY_FIELDS = ['com.pyxis.greenhopper.jira:gh-epic-link', 'com.atlassian.jpo:jpo-custom-field-parent'];

  public function build(array $issue, string $fieldId, string $jiraField, array $meta, string $fallbackName): array|false {
    $schema = is_array($meta['schema'] ?? null) ? $meta['schema'] : [];
    $type = $jiraField === 'description' ? 'doc' : (string)($schema['type'] ?? '');
    $items = (string)($schema['items'] ?? '');
    $operations = array_values(array_map('strval', is_array($meta['operations'] ?? null) ? $meta['operations'] : []));
    if ($operations !== [] && !in_array('set', $operations, true)) {
      return false;
    }
    $raw = $this->currentValue($issue, $fieldId, $jiraField);
    $name = (string)($meta['name'] ?? $fallbackName);
    $base = [
      'fieldId' => $fieldId,
      'jiraField' => $jiraField,
      'name' => $name,
      'type' => $type,
      'items' => $items,
      'required' => !empty($meta['required']),
      'schema' => $schema,
    ];
    $allowed = is_array($meta['allowedValues'] ?? null) ? $this->choiceOptions($meta['allowedValues']) : [];
    if ($allowed !== []) {
      if ($type !== 'array' && empty($meta['required'])) {
        array_unshift($allowed, ['value' => '', 'text' => '(None)', 'payload' => null]);
      }
      return $base + [
        'kind' => $type === 'array' ? 'multi-choice' : 'choice',
        'options' => $allowed,
        'value' => $this->choiceValue($raw, $allowed, $type === 'array'),
      ];
    }
    if ($type === 'issuelink' || ($type === 'any' && in_array($schema['custom'] ?? '', self::ISSUE_KEY_FIELDS, true))) {
      return $base + ['kind' => 'issue-key', 'value' => $this->issueKeyText($raw)];
    }
    return match ($type) {
      'doc' => $base + ['kind' => 'doc', 'value' => is_array($raw) ? Adf::toText($raw) : ''],
      'string' => $base + ['kind' => 'string', 'value' => is_scalar($raw) ? (string)$raw : $this->formatValue($raw)],
      'number' => $base + ['kind' => 'number', 'value' => is_scalar($raw) ? (string)$raw : ''],
      'date' => $base + ['kind' => 'date', 'value' => is_string($raw) && $raw !== '' ? substr($raw, 0, 10) : date('Y-m-d')],
      'datetime' => $base + ['kind' => 'datetime', 'value' => is_scalar($raw) ? (string)$raw : ''],
      'boolean' => $base + ['kind' => 'boolean', 'value' => (bool)$raw],
      'array' => $items === 'string' ? $base + ['kind' => 'string-list', 'value' => $this->stringListText($raw)] : false,
      default => false,
    };
  }

  public function saveValue(array $model, mixed $editorValue): mixed {
    $value = match ((string)$model['kind']) {
      'doc' => Adf::fromMarkdown($this->normalizeText($editorValue)),
      'string' => trim((string)$editorValue),
      'number' => $this->numberValue($editorValue),
      'date' => $this->dateValue($editorValue),
      'datetime' => trim((string)$editorValue),
      'boolean' => (bool)$editorValue,
      'choice' => $this->singleChoiceValue($model, $editorValue),
      'multi-choice' => $this->multiChoiceValue($model, $editorValue),
      'string-list' => $this->stringListValue((string)$editorValue),
      'issue-key' => $this->issueKeyValue($model, $editorValue),
      default => throw new \InvalidArgumentException('Unsupported editor kind.'),
    };
    if (!empty($model['required']) && ($value === null || $value === '' || $value === [])) {
      throw new \InvalidArgumentException('This property requires a value.');
    }
    return $value;
  }

  private function currentValue(array $issue, string $fieldId, string $jiraField): mixed {
    $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
    return $fieldId === 'key' ? ($issue['key'] ?? '') : ($fields[$jiraField] ?? null);
  }

  private function choiceOptions(array $allowedValues): array {
    $options = [];
    foreach ($allowedValues as $value) {
      if (!is_array($value)) {
        continue;
      }
      $key = $this->choiceKey($value);
      if ($key === '') {
        continue;
      }
      $options[] = [
        'value' => $key,
        'text' => $this->choiceLabel($value),
        'payload' => $this->choicePayload($value),
      ];
    }
    return $options;
  }

  private function choiceValue(mixed $raw, array $options, bool $multiple): string|array {
    if ($multiple) {
      $values = [];
      foreach (is_array($raw) ? $raw : [] as $item) {
        if (is_array($item)) {
          $key = $this->matchingChoiceKey($item, $options);
          if ($key !== '') {
            $values[] = $key;
          }
        }
      }
      return $values;
    }
    return is_array($raw) ? $this->matchingChoiceKey($raw, $options) : '';
  }

  private function matchingChoiceKey(array $raw, array $options): string {
    $rawKey = $this->choiceKey($raw);
    foreach ($options as $option) {
      if ((string)($option['value'] ?? '') === $rawKey) {
        return $rawKey;
      }
    }
    $rawLabel = $this->choiceLabel($raw);
    foreach ($options as $option) {
      if ((string)($option['text'] ?? '') === $rawLabel) {
        return (string)$option['value'];
      }
    }
    return '';
  }

  private function choiceKey(array $value): string {
    foreach (['accountId', 'id', 'value', 'key', 'name'] as $key) {
      if (isset($value[$key]) && is_scalar($value[$key])) {
        return (string)$value[$key];
      }
    }
    return '';
  }

  private function choiceLabel(array $value): string {
    foreach (['displayName', 'name', 'value', 'key', 'id', 'accountId'] as $key) {
      if (isset($value[$key]) && is_scalar($value[$key])) {
        return (string)$value[$key];
      }
    }
    return $this->choiceKey($value);
  }

  private function choicePayload(array $value): array {
    foreach (['accountId', 'id', 'value', 'key', 'name'] as $key) {
      if (isset($value[$key]) && is_scalar($value[$key])) {
        return [$key => (string)$value[$key]];
      }
    }
    return $value;
  }

  private function payloadForChoice(string $key, array $options): mixed {
    if ($key === '') {
      return null;
    }
    foreach ($options as $option) {
      if ((string)($option['value'] ?? '') === $key) {
        return $option['payload'] ?? $key;
      }
    }
    throw new \InvalidArgumentException('Select an available value.');
  }

  private function singleChoiceValue(array $model, mixed $editorValue): mixed {
    $key = (string)$editorValue;
    if ($key === '' && !empty($model['required'])) {
      throw new \InvalidArgumentException('Select a value.');
    }
    return $this->payloadForChoice($key, (array)$model['options']);
  }

  private function multiChoiceValue(array $model, mixed $editorValue): array {
    $keys = FilterState::stringList((array)$editorValue);
    if ($keys === [] && !empty($model['required'])) {
      throw new \InvalidArgumentException('Select at least one value.');
    }
    return array_values(array_map(fn(string $key): mixed => $this->payloadForChoice($key, (array)$model['options']), $keys));
  }

  private function normalizeText(mixed $value): string {
    if (is_array($value)) {
      $value = implode("\n", array_map('strval', $value));
    }
    return trim((string)$value);
  }

  private function numberValue(mixed $value): int|float|null {
    $text = trim((string)$value);
    if ($text === '') {
      return null;
    }
    if (!is_numeric($text) || !is_finite((float)$text)) {
      throw new \InvalidArgumentException('Enter a valid number.');
    }
    return strpbrk($text, '.eE') !== false ? (float)$text : (int)$text;
  }

  private function dateValue(mixed $value): ?string {
    $text = trim((string)$value);
    if ($text === '') {
      return null;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    if (!$date || $date->format('Y-m-d') !== $text) {
      throw new \InvalidArgumentException('Date must use YYYY-MM-DD.');
    }
    return $text;
  }

  /** Read a key from a parent object, a Parent Link wrapper, or an Epic Link string. */
  private function issueKeyText(mixed $raw): string {
    if (is_array($raw)) {
      $raw = $raw['key'] ?? $raw['data']['key'] ?? '';
    }
    return is_scalar($raw) ? (string)$raw : '';
  }

  /** Validate an issue key; parent takes an object, the custom link fields take the key. */
  private function issueKeyValue(array $model, mixed $editorValue): array|string|null {
    $key = FilterState::parentKey($editorValue);
    if ($key === '') {
      return null;
    }
    if (preg_match('/^[A-Z][A-Z0-9_]*-[0-9]+$/D', $key) !== 1) {
      throw new \InvalidArgumentException('Enter an issue key like AP-123.');
    }
    return $model['type'] === 'issuelink' ? ['key' => $key] : $key;
  }

  private function stringListText(mixed $value): string {
    if (!is_array($value)) {
      return '';
    }
    return implode(', ', array_map('strval', $value));
  }

  private function stringListValue(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), fn(string $item): bool => $item !== ''));
  }

  private function formatValue(mixed $value): string {
    if ($value === null || $value === false) {
      return '';
    }
    if (is_scalar($value)) {
      return (string)$value;
    }
    if (is_array($value)) {
      foreach (['value', 'name', 'displayName', 'key'] as $key) {
        if (isset($value[$key]) && is_scalar($value[$key])) {
          return (string)$value[$key];
        }
      }
      return implode(', ', array_map(fn(mixed $item): string => $this->formatValue($item), $value));
    }
    return '';
  }

}
