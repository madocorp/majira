<?php

namespace MAJIRA\Jira;

/** Minimal REST client for Jira Cloud, authenticated with an email + API token. */
class Client {

  public function __construct(
    private string $site,
    private string $email,
    private string $apiToken
  ) {
    $this->site = rtrim($this->site, '/');
  }

  /** Returns the authenticated Jira user. */
  public function myself(): array {
    return $this->request('GET', '/rest/api/3/myself');
  }

  /** Returns projects visible to the current user. */
  public function projects(int $startAt = 0, int $maxResults = 100): array {
    return $this->request('GET', '/rest/api/3/project/search', [
      'startAt' => $startAt,
      'maxResults' => $maxResults,
      'orderBy' => 'name',
    ]);
  }

  /** Return one project's details, including its numeric Jira ID. */
  public function project(string $projectKey): array {
    return $this->request('GET', '/rest/api/3/project/' . rawurlencode($projectKey));
  }

  /** Return priorities available in one project. */
  public function projectPriorities(string $projectId, int $startAt = 0, int $maxResults = 100): array {
    return $this->request('GET', '/rest/api/3/priority/search', [
      'projectId' => $projectId,
      'startAt' => $startAt,
      'maxResults' => $maxResults,
    ]);
  }

  /** Return one page of Jira priorities across projects. */
  public function allPriorities(int $startAt = 0, int $maxResults = 100): array {
    return $this->request('GET', '/rest/api/3/priority/search', [
      'startAt' => $startAt,
      'maxResults' => $maxResults,
    ]);
  }

  /** Returns Jira Software boards, optionally scoped to a project. */
  public function boards(string $projectKey = '', int $startAt = 0, int $maxResults = 100): array {
    $params = [
      'startAt' => $startAt,
      'maxResults' => $maxResults,
    ];
    if ($projectKey !== '') {
      $params['projectKeyOrId'] = $projectKey;
    }
    return $this->request('GET', '/rest/agile/1.0/board', $params);
  }

  /** Returns Jira Software sprints for a board. */
  public function boardSprints(int $boardId, int $startAt = 0, int $maxResults = 100): array {
    return $this->request('GET', '/rest/agile/1.0/board/' . $boardId . '/sprint', [
      'startAt' => $startAt,
      'maxResults' => $maxResults,
    ]);
  }

  /** Return statuses used by active Jira workflows. */
  public function statuses(): array {
    return $this->request('GET', '/rest/api/3/status');
  }

  /** Return issue types visible to the authenticated user. */
  public function issueTypes(): array {
    return $this->request('GET', '/rest/api/3/issuetype');
  }

  /** Returns Jira field metadata, including custom field IDs. */
  public function fields(): array {
    return $this->request('GET', '/rest/api/3/field');
  }

  /** Returns issue types creatable in a project. */
  public function createIssueTypes(string $projectKey): array {
    return $this->request('GET', '/rest/api/3/issue/createmeta/' . rawurlencode($projectKey) . '/issuetypes');
  }

  /** Creates an issue and returns its created key/id payload. */
  public function createIssue(array $fields): array {
    return $this->request('POST', '/rest/api/3/issue', [
      'fields' => $fields,
    ]);
  }

  /** Returns users who can be assigned issues in a project. */
  public function assignableUsers(string $projectKey): array {
    return $this->request('GET', '/rest/api/3/user/assignable/search', [
      'project' => $projectKey,
      'maxResults' => 1000,
    ]);
  }

  /** Returns users who can be assigned to a specific issue. */
  public function assignableUsersForIssue(string $issueKey): array {
    return $this->request('GET', '/rest/api/3/user/assignable/search', [
      'issueKey' => $issueKey,
      'maxResults' => 1000,
    ]);
  }

  /** Returns users visible to the authenticated user. */
  public function users(string $query = ''): array {
    return $this->request('GET', '/rest/api/3/user/search', [
      'query' => $query,
      'maxResults' => 1000,
    ]);
  }

  /** Runs a paged JQL search and returns the raw decoded response. */
  public function search(string $jql, array $fields, int $maxResults = 100, string|false $nextPageToken = false): array {
    $params = [
      'jql' => $jql,
      'fields' => $fields,
      'maxResults' => $maxResults,
    ];
    if ($nextPageToken !== false && $nextPageToken !== '') {
      $params['nextPageToken'] = $nextPageToken;
    }
    return $this->request('POST', '/rest/api/3/search/jql', $params);
  }

  /** Returns a single issue with the requested fields. */
  public function issue(string $key, array $fields): array {
    return $this->request('GET', '/rest/api/3/issue/' . rawurlencode($key), [
      'fields' => implode(',', $fields),
    ]);
  }

  /** Returns fields editable by the current user for an issue. */
  public function editMeta(string $issueKey): array {
    return $this->request('GET', '/rest/api/3/issue/' . rawurlencode($issueKey) . '/editmeta');
  }

  /** Updates issue fields through Jira's generic edit endpoint. */
  public function updateIssueFields(string $issueKey, array $fields): array {
    return $this->request('PUT', '/rest/api/3/issue/' . rawurlencode($issueKey), [
      'fields' => $fields,
    ]);
  }

  /** Returns workflow transitions available for an issue. */
  public function transitions(string $issueKey): array {
    return $this->request('GET', '/rest/api/3/issue/' . rawurlencode($issueKey) . '/transitions');
  }

  /** Performs a workflow transition on an issue. */
  public function transitionIssue(string $issueKey, string $transitionId): array {
    return $this->request('POST', '/rest/api/3/issue/' . rawurlencode($issueKey) . '/transitions', [
      'transition' => ['id' => $transitionId],
    ]);
  }

  /** Adds a comment to an issue. */
  public function addComment(string $issueKey, array $body): array {
    return $this->request('POST', '/rest/api/3/issue/' . rawurlencode($issueKey) . '/comment', [
      'body' => $body,
    ]);
  }

  /** Updates an existing issue comment. */
  public function updateComment(string $issueKey, string $commentId, array $body): array {
    return $this->request('PUT', '/rest/api/3/issue/' . rawurlencode($issueKey) . '/comment/' . rawurlencode($commentId), [
      'body' => $body,
    ]);
  }

  /** Deletes an issue comment. */
  public function deleteComment(string $issueKey, string $commentId): array {
    return $this->request('DELETE', '/rest/api/3/issue/' . rawurlencode($issueKey) . '/comment/' . rawurlencode($commentId));
  }

  /** Deletes an attachment. */
  public function deleteAttachment(string $attachmentId): array {
    return $this->request('DELETE', '/rest/api/3/attachment/' . rawurlencode($attachmentId));
  }

  /** Downloads an attachment content URL to a local file path. */
  public function download(string $url, string $path): void {
    if (!function_exists('curl_init')) {
      throw new \RuntimeException('The PHP curl extension is required for Jira communication.');
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
      throw new \RuntimeException("Could not create download directory: {$directory}");
    }
    $temp = $path . '.part';
    $file = fopen($temp, 'wb');
    if ($file === false) {
      throw new \RuntimeException("Could not write attachment cache file: {$temp}");
    }
    $handle = curl_init();
    curl_setopt_array($handle, [
      CURLOPT_URL => $url,
      CURLOPT_FILE => $file,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_USERPWD => "{$this->email}:{$this->apiToken}",
      CURLOPT_TIMEOUT => 120,
      CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    $ok = curl_exec($handle);
    $error = curl_error($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    fclose($file);
    if ($ok === false || $status >= 400) {
      @unlink($temp);
      throw new \RuntimeException($ok === false ? "Jira download failed: {$error}" : "Jira download failed with HTTP {$status}.");
    }
    if (!rename($temp, $path)) {
      @unlink($temp);
      throw new \RuntimeException("Could not move attachment download into cache: {$path}");
    }
  }

  /** Returns issues shown by a Jira Software board. */
  public function boardIssues(int $boardId, string $jql, array $fields, int $maxResults = 100, int $startAt = 0): array {
    return $this->request('GET', '/rest/agile/1.0/board/' . $boardId . '/issue', [
      'jql' => $jql,
      'fields' => implode(',', $fields),
      'startAt' => $startAt,
      'maxResults' => $maxResults,
    ]);
  }

  /** Returns issue types and their statuses available in a project. */
  public function projectStatuses(string $projectKey): array {
    return $this->request('GET', '/rest/api/3/project/' . rawurlencode($projectKey) . '/statuses');
  }

  /** Returns issues shown by a Jira Software board sprint. */
  public function boardSprintIssues(int $boardId, int $sprintId, string $jql, array $fields, int $maxResults = 100, int $startAt = 0): array {
    return $this->request('GET', '/rest/agile/1.0/board/' . $boardId . '/sprint/' . $sprintId . '/issue', [
      'jql' => $jql,
      'fields' => implode(',', $fields),
      'startAt' => $startAt,
      'maxResults' => $maxResults,
    ]);
  }

  /** Moves issues to a Jira Software sprint. */
  public function moveIssuesToSprint(int $sprintId, array $issueKeys): array {
    return $this->request('POST', '/rest/agile/1.0/sprint/' . $sprintId . '/issue', [
      'issues' => array_values(array_map('strval', $issueKeys)),
    ]);
  }

  /** Returns Jira Software board configuration, including column status mappings. */
  public function boardConfiguration(int $boardId): array {
    return $this->request('GET', '/rest/agile/1.0/board/' . $boardId . '/configuration');
  }

  private function request(string $method, string $path, array $params = []): array {
    if (!function_exists('curl_init')) {
      throw new \RuntimeException('The PHP curl extension is required for Jira communication.');
    }
    $url = $this->site . $path;
    $handle = curl_init();
    $headers = ['Accept: application/json'];
    $options = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_USERPWD => "{$this->email}:{$this->apiToken}",
      CURLOPT_TIMEOUT => 20,
      CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($method === 'GET' && $params !== []) {
      $url .= '?' . http_build_query($params);
    } else if ($params !== []) {
      $headers[] = 'Content-Type: application/json';
      $options[CURLOPT_HTTPHEADER] = $headers;
      $options[CURLOPT_POSTFIELDS] = json_encode($params);
    }
    $options[CURLOPT_URL] = $url;
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    if ($body === false) {
      $error = curl_error($handle);
      curl_close($handle);
      throw new \RuntimeException("Jira request failed: {$error}");
    }
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $data = json_decode($body, true);
    if ($status >= 400) {
      $message = is_array($data) ? self::errorMessage($data) : $body;
      throw new \RuntimeException("Jira API error {$status}: {$message}");
    }
    return is_array($data) ? $data : [];
  }

  private static function errorMessage(array $data): string {
    if (!empty($data['errorMessages'])) {
      return implode('; ', $data['errorMessages']);
    }
    if (!empty($data['message'])) {
      return (string)$data['message'];
    }
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown error';
  }

}
