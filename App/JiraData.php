<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Client;
use SPTK\Core\AppData;

/** Loads Jira data while retaining the old Majira cache formats. */
class JiraData {

  /** Create a client from saved connection settings. */
  public function client(): Client {
    $settings = Settings::load();
    if (!Settings::isConfigured($settings)) {
      throw new \RuntimeException('Save the Jira site, email and API token in Settings first.');
    }
    return new Client(
      trim((string)$settings['site']),
      trim((string)$settings['email']),
      trim((string)$settings['apiToken'])
    );
  }

  /** Load and cache every visible project. */
  public function projects(): array {
    $projects = [];
    $start = 0;
    do {
      $page = $this->client()->projects($start);
      foreach ($page['values'] ?? [] as $project) {
        if (!empty($project['key'])) {
          $projects[] = ['key' => (string)$project['key'], 'name' => (string)($project['name'] ?? $project['key'])];
        }
      }
      $loaded = count($page['values'] ?? []);
      $start += $loaded;
    } while ($loaded > 0 && empty($page['isLast']));
    ProjectCache::save($projects);
    return ProjectCache::load();
  }

  /** Load and cache boards for one project. */
  public function boards(string $projectKey): array {
    $boards = [];
    $start = 0;
    do {
      $page = $this->client()->boards($projectKey, $start);
      foreach ($page['values'] ?? [] as $board) {
        if (!empty($board['id'])) {
          $boards[] = ['id' => (string)$board['id'], 'name' => (string)($board['name'] ?? $board['id'])];
        }
      }
      $loaded = count($page['values'] ?? []);
      $start += $loaded;
    } while ($loaded > 0 && empty($page['isLast']));
    BoardCache::save($projectKey, $boards);
    return BoardCache::load($projectKey);
  }

  /** Load and cache sprints for one board. */
  public function sprints(string $boardId): array {
    $sprints = [];
    $start = 0;
    do {
      $page = $this->client()->boardSprints((int)$boardId, $start);
      foreach ($page['values'] ?? [] as $sprint) {
        if (!empty($sprint['id'])) {
          $sprints[] = [
            'id' => (string)$sprint['id'],
            'name' => (string)($sprint['name'] ?? $sprint['id']),
            'state' => (string)($sprint['state'] ?? ''),
          ];
        }
      }
      $loaded = count($page['values'] ?? []);
      $start += $loaded;
    } while ($loaded > 0 && empty($page['isLast']));
    SprintCache::save($boardId, $sprints);
    return SprintCache::load($boardId);
  }

  /** Load project-wide filter choices once, unless explicitly refreshed. */
  public function filterOptions(string $projectKey, bool $refresh = false): array {
    $scope = $projectKey === '' ? 'global' : 'project';
    $cached = FilterCache::load($projectKey);
    if (!$refresh && $cached['_loaded'] && $cached['priorityScope'] === $scope) {
      return $cached;
    }
    $client = $this->client();
    $statuses = [];
    $types = [];
    if ($projectKey === '') {
      foreach ($client->statuses() as $status) {
        $statuses[] = (string)($status['name'] ?? '');
      }
      foreach ($client->issueTypes() as $type) {
        $types[] = (string)($type['name'] ?? '');
      }
    } else {
      foreach ($client->projectStatuses($projectKey) as $type) {
        $types[] = (string)($type['name'] ?? '');
        foreach ($type['statuses'] ?? [] as $status) {
          $statuses[] = (string)($status['name'] ?? '');
        }
      }
      $project = $client->project($projectKey);
      $projectId = (string)($project['id'] ?? '');
      if ($projectId === '') {
        throw new \RuntimeException('Jira did not return an ID for project ' . $projectKey . '.');
      }
    }
    $priorities = [];
    $start = 0;
    do {
      $page = $projectKey === '' ? $client->allPriorities($start) : $client->projectPriorities($projectId, $start);
      foreach ($page['values'] ?? [] as $priority) {
        $priorities[] = (string)($priority['name'] ?? '');
      }
      $loaded = count($page['values'] ?? []);
      $start += $loaded;
    } while ($loaded > 0 && empty($page['isLast']));
    $users = $projectKey === '' ? $client->users() : $client->assignableUsers($projectKey);
    $assignees = array_values(array_filter(array_map(fn(array $row): string => (string)($row['accountId'] ?? ''), $users)));
    FilterCache::save($projectKey, [
      '_loaded' => true,
      'priorityScope' => $scope,
      'status' => array_values(array_unique(array_filter($statuses))),
      'type' => array_values(array_unique(array_filter($types))),
      'priority' => array_values(array_unique(array_filter($priorities))),
      'assignee' => $assignees,
      'assigneeUsers' => $users,
    ]);
    return FilterCache::load($projectKey);
  }

  /** Search the current JQL and preserve the result page for offline startup. */
  public function tickets(string $jql, bool $more = false): array {
    $cached = TicketCache::load();
    $state = ['jql' => $jql];
    if ($more && ($cached['state'] ?? null) !== $state) {
      throw new \RuntimeException('Search scope changed. Refresh the ticket list first.');
    }
    $issues = $more && is_array($cached['issues'] ?? null) ? $cached['issues'] : [];
    $meta = $more && is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
    $fields = ['summary', 'status', 'assignee', 'issuetype', 'priority', 'updated'];
    $token = $more ? (string)($meta['nextPageToken'] ?? '') : '';
    $page = $this->client()->search($jql, $fields, 100, $token === '' ? false : $token);
    $meta = [
      'nextPageToken' => (string)($page['nextPageToken'] ?? ''),
      'hasMore' => !empty($page['nextPageToken']),
      'loaded' => count($issues) + count($page['issues'] ?? []),
    ];
    $issues = array_merge($issues, is_array($page['issues'] ?? null) ? $page['issues'] : []);
    TicketCache::save($state, $issues, $meta);
    return ['issues' => $issues, 'meta' => $meta];
  }

  /** Return a cached issue unless a fresh Jira request is requested. */
  public function ticket(string $key, bool $refresh = false): array {
    $cache = AppData::loadJson('ticket-details.json');
    if (!$refresh && is_array($cache[$key] ?? null)) {
      return $cache[$key];
    }
    $fields = ['summary', 'description', 'status', 'assignee', 'reporter', 'priority', 'issuetype', 'created', 'updated', 'comment', 'attachment', 'labels', 'project'];
    $issue = $this->client()->issue($key, $fields);
    $cache[$key] = $issue;
    AppData::saveJson('ticket-details.json', $cache);
    return $issue;
  }

  /** Report whether an issue can be opened without Jira I/O. */
  public function hasCachedTicket(string $key): bool {
    $cache = AppData::loadJson('ticket-details.json');
    return is_array($cache[$key] ?? null);
  }

  /** Read cached creatable issue types for a project. */
  public function cachedIssueTypes(string $projectKey): array {
    $cache = AppData::loadJson('issue-types.json');
    return is_array($cache[$projectKey] ?? null) ? $cache[$projectKey] : [];
  }

  /** Load and cache issue types accepted by Jira's creation endpoint. */
  public function issueTypes(string $projectKey): array {
    $client = $this->client();
    try {
      $response = $client->createIssueTypes($projectKey);
      $records = is_array($response['issueTypes'] ?? null) ? $response['issueTypes'] : [];
    } catch (\Throwable) {
      $records = $client->projectStatuses($projectKey);
    }
    $types = [];
    foreach ($records as $record) {
      if (!is_array($record) || !empty($record['subtask'])) {
        continue;
      }
      $id = trim((string)($record['id'] ?? ''));
      $name = trim((string)($record['name'] ?? ''));
      if ($id !== '' || $name !== '') {
        $types[] = ['id' => $id, 'name' => $name ?: $id];
      }
    }
    $cache = AppData::loadJson('issue-types.json');
    $cache[$projectKey] = $types;
    AppData::saveJson('issue-types.json', $cache);
    return $types;
  }

  /** Create a Jira issue and return its key. */
  public function createTicket(string $projectKey, array $type, string $summary, string $description): string {
    $fields = [
      'project' => ['key' => $projectKey],
      'issuetype' => ($type['id'] ?? '') === '' ? ['name' => (string)$type['name']] : ['id' => (string)$type['id']],
      'summary' => $summary,
    ];
    if ($description !== '') {
      $fields['description'] = \MAJIRA\Jira\Adf::fromMarkdown($description);
    }
    $created = $this->client()->createIssue($fields);
    $key = trim((string)($created['key'] ?? ''));
    if ($key === '') {
      throw new \RuntimeException('Jira did not return an issue key.');
    }
    return $key;
  }

  /** Remove every persisted Jira response while leaving settings in place. */
  public function clearCaches(): void {
    ProjectCache::clear();
    BoardCache::clear();
    SprintCache::clear();
    FilterCache::clear();
    TicketCache::clear();
    AppData::saveJson('ticket-details.json', []);
    AppData::saveJson('issue-types.json', []);
  }
}
