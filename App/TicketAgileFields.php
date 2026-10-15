<?php

namespace MAJIRA\App;

/** Edits Sprint and board estimates through Jira Software, which works even when edit screens omit them. */
class TicketAgileFields {

  private const SPRINT_SCHEMA = 'com.pyxis.greenhopper.jira:gh-sprint';

  public function __construct(private JiraData $data) {
  }

  /** Report whether Jira edit metadata describes the Sprint field. */
  public static function isSprint(?array $meta): bool {
    return ($meta['schema']['custom'] ?? '') === self::SPRINT_SCHEMA;
  }

  /** Prefer the Board screen board, then the List board, when the project has it. */
  public function boardId(string $projectKey): string {
    $boards = BoardCache::has($projectKey) ? BoardCache::load($projectKey) : $this->data->boards($projectKey);
    $ids = array_column($boards, 'id');
    foreach ([(string)BoardState::load()['boardId'], (string)(Settings::load()['boardId'] ?? '')] as $preferred) {
      if ($preferred !== '' && in_array($preferred, $ids, true)) {
        return $preferred;
      }
    }
    return (string)($ids[0] ?? '');
  }

  /** List the board's open sprints and the backlog, selecting the ticket's open sprint. */
  public function sprintModel(array $issue, string $jiraField, string $name, string $boardId): array {
    $sprints = SprintCache::has($boardId) ? SprintCache::load($boardId) : $this->data->sprints($boardId);
    $options = [['value' => '', 'text' => 'Backlog']];
    foreach ($sprints as $sprint) {
      if (strtolower($sprint['state']) !== 'closed') {
        $options[] = ['value' => $sprint['id'], 'text' => $sprint['name'] . '  ' . $sprint['state']];
      }
    }
    $value = '';
    $current = null;
    foreach ((array)($issue['fields'][$jiraField] ?? []) as $sprint) {
      if (is_array($sprint) && strtolower((string)($sprint['state'] ?? '')) !== 'closed') {
        $value = (string)($sprint['id'] ?? '');
        $current = $sprint;
      }
    }
    if ($current !== null && !in_array($value, array_column($options, 'value'), true)) {
      $options[] = ['value' => $value, 'text' => (string)($current['name'] ?? $value) . '  ' . (string)($current['state'] ?? '')];
    }
    return [
      'kind' => 'sprint', 'name' => $name, 'jiraField' => $jiraField, 'boardId' => $boardId,
      'options' => $options, 'value' => $value, 'required' => false,
    ];
  }

  /** Build a number input when the field is the board's custom estimation field. */
  public function estimateModel(array $issue, string $jiraField, string $name, string $boardId): ?array {
    $configuration = $this->data->client()->boardConfiguration((int)$boardId);
    $estimate = (string)($configuration['estimation']['field']['fieldId'] ?? '');
    if ($estimate !== $jiraField || !str_starts_with($estimate, 'customfield_')) {
      return null;
    }
    $raw = $issue['fields'][$jiraField] ?? null;
    return [
      'kind' => 'estimate', 'name' => $name, 'jiraField' => $jiraField, 'boardId' => $boardId,
      'value' => is_scalar($raw) ? (string)$raw : '', 'required' => false,
    ];
  }

  /** Validate an editor value for a sprint move or estimate write. */
  public function saveValue(array $model, mixed $editorValue): ?string {
    $text = trim((string)$editorValue);
    if ($model['kind'] === 'sprint') {
      if (!in_array($text, array_column((array)$model['options'], 'value'), true)) {
        throw new \InvalidArgumentException('Select an available sprint.');
      }
      return $text;
    }
    if ($text === '') {
      return null;
    }
    if (!is_numeric($text) || !is_finite((float)$text)) {
      throw new \InvalidArgumentException('Enter a valid number.');
    }
    return $text;
  }

  /** Move the issue to a sprint or the backlog, or write its board estimate. */
  public function save(string $issueKey, array $model, ?string $value): void {
    $client = $this->data->client();
    if ($model['kind'] !== 'sprint') {
      $client->setIssueEstimation($issueKey, (int)$model['boardId'], $value);
    } else if ($value === '' || $value === null) {
      $client->moveIssuesToBacklog([$issueKey]);
    } else {
      $client->moveIssuesToSprint((int)$value, [$issueKey]);
    }
  }

}
