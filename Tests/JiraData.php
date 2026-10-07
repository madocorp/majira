<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');

require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\JiraData;
use MAJIRA\App\FilterState;
use MAJIRA\App\JqlBuilder;
use MAJIRA\App\Settings;
use MAJIRA\App\TicketCache;
use MAJIRA\Jira\Client;

/** Exercise cache reuse and page scope with a fake Jira client. */
class FakeClient extends Client {

  public int $searches = 0;
  public int $details = 0;

  public function __construct() {
    parent::__construct('https://example.atlassian.net', 'user@example.com', 'test');
  }

  public function search(string $jql, array $fields, int $maxResults = 100, string|false $nextPageToken = false): array {
    $this->searches++;
    return $nextPageToken === false
      ? ['issues' => [['key' => 'AP-1', 'fields' => ['summary' => 'First']]], 'nextPageToken' => 'page-2']
      : ['issues' => [['key' => 'AP-2', 'fields' => ['summary' => 'Second']]]];
  }

  public function issue(string $key, array $fields): array {
    $this->details++;
    return ['key' => $key, 'fields' => ['summary' => 'Detail']];
  }

  public function createIssueTypes(string $projectKey): array {
    return ['issueTypes' => [['id' => '10001', 'name' => 'Task'], ['id' => '10002', 'name' => 'Subtask', 'subtask' => true]]];
  }

  public function createIssue(array $fields): array {
    check($fields['issuetype']['id'] === '10001', 'Created ticket used the wrong issue type.');
    check($fields['summary'] === 'New task', 'Created ticket used the wrong summary.');
    return ['key' => 'AP-3'];
  }
}

/** Supply the fake client to normal cache and paging code. */
class FakeJiraData extends JiraData {

  public function __construct(public FakeClient $fake) {
  }

  public function client(): Client {
    return $this->fake;
  }
}

/** Fail the test on an unexpected result. */
function check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$testHome = sys_get_temp_dir() . '/majira-test-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
Settings::save([
  'site' => 'https://example.atlassian.net',
  'email' => 'user@example.com',
  'apiToken' => 'test',
  'projectKey' => 'AP',
]);
check((new JqlBuilder())->current() === 'project = "AP"', 'Project must appear in generated JQL.');
$settings = Settings::load();
$settings['boardId'] = '12';
$settings['sprintId'] = '34';
Settings::save($settings);
check((new JqlBuilder())->current() === 'project = "AP" AND sprint = 34', 'Sprint must appear in generated JQL without a board clause.');
$settings['sprintId'] = JqlBuilder::NO_SPRINTS;
Settings::save($settings);
check((new JqlBuilder())->current() === 'project = "AP" AND sprint is EMPTY', 'No-sprint choice must appear in generated JQL.');
$settings['sprintId'] = '';
Settings::save($settings);
check(FilterState::normalize(['customJql' => 'text ~ "old"'])['lastJql'] === 'text ~ "old"', 'Existing raw JQL must migrate to Last JQL.');
$filters = FilterState::load();
$filters['lastJql'] = 'text ~ "draft"';
$filters['customJql'] = $filters['lastJql'];
FilterState::save($filters);
check((new JqlBuilder())->current() === 'text ~ "draft"', 'Edited JQL must become the current query.');
$filters['customFilters'] = [['name' => 'Saved', 'jql' => 'project = "AP"']];
$filters['selectedCustomFilter'] = 'Saved';
$filters['customJql'] = '';
FilterState::save($filters);
check((new JqlBuilder())->current() === 'project = "AP"', 'Saved filter must become the current query.');
check(FilterState::load()['lastJql'] === 'text ~ "draft"', 'Selecting a saved filter must retain Last JQL.');
$filters = FilterState::load();
$filters['customJql'] = $filters['lastJql'];
$filters['selectedCustomFilter'] = '';
FilterState::save($filters);
check((new JqlBuilder())->current() === 'text ~ "draft"', 'Last JQL must be restorable after selecting a saved filter.');
FilterState::save(FilterState::defaults());
$fake = new FakeClient();
$data = new FakeJiraData($fake);
$first = $data->tickets('project = "AP"');
check(count($first['issues']) === 1 && $first['meta']['hasMore'], 'First page was not cached.');
$settings['boardId'] = '99';
Settings::save($settings);
check((new JqlBuilder())->current() === 'project = "AP"', 'Changing boards must not change the ticket query.');
$second = $data->tickets('project = "AP"', true);
check(count($second['issues']) === 2 && $fake->searches === 2, 'Next page was not appended.');
check(count(TicketCache::load()['issues']) === 2, 'Ticket cache did not retain both pages.');
try {
  $data->tickets('project = "OTHER"', true);
  throw new RuntimeException('Changed search scope was accepted.');
} catch (RuntimeException $error) {
  check($error->getMessage() === 'Search scope changed. Refresh the ticket list first.', 'Unexpected scope error.');
}
$data->ticket('AP-1');
$data->ticket('AP-1');
check($fake->details === 1, 'Ticket detail cache was not reused.');
$data->ticket('AP-1', true);
check($fake->details === 2, 'Fresh ticket request did not bypass the cache.');
$types = $data->issueTypes('AP');
check(count($types) === 1 && $data->cachedIssueTypes('AP') === $types, 'Issue type cache was not reused.');
check($data->createTicket('AP', $types[0], 'New task', '') === 'AP-3', 'Ticket creation failed.');
$data->clearCaches();
check(TicketCache::load() === [], 'Ticket cache was not cleared.');
check($data->cachedIssueTypes('AP') === [], 'Issue type cache was not cleared.');
foreach (glob($testHome . '/.majira/*') ?: [] as $file) {
  unlink($file);
}
rmdir($testHome . '/.majira');
rmdir($testHome);
echo "JiraData OK\n";
