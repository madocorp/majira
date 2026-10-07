<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');

require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\JiraData;
use MAJIRA\App\BoardCache;
use MAJIRA\App\FilterCache;
use MAJIRA\App\FilterState;
use MAJIRA\App\JqlBuilder;
use MAJIRA\App\Settings;
use MAJIRA\App\SprintCache;
use MAJIRA\App\TicketCache;
use MAJIRA\Jira\Client;
use SPTK\Core\AppData;

/** Exercise cache reuse and page scope with a fake Jira client. */
class FakeClient extends Client {

  public int $searches = 0;
  public int $details = 0;
  public int $boardRequests = 0;
  public int $sprintRequests = 0;
  public int $filterRequests = 0;
  public int $priorityRequests = 0;

  public function __construct() {
    parent::__construct('https://example.atlassian.net', 'user@example.com', 'test');
  }

  public function search(string $jql, array $fields, int $maxResults = 100, string|false $nextPageToken = false): array {
    $this->searches++;
    return $nextPageToken === false
      ? ['issues' => [['key' => 'AP-1', 'fields' => ['summary' => 'First']]], 'nextPageToken' => 'page-2']
      : ['issues' => [['key' => 'AP-2', 'fields' => ['summary' => 'Second']]]];
  }

  public function boards(string $projectKey = '', int $startAt = 0, int $maxResults = 100): array {
    $this->boardRequests++;
    return ['values' => $projectKey === 'EMPTY' ? [] : [['id' => (string)(100 + $this->boardRequests), 'name' => $projectKey . ' board']], 'isLast' => true];
  }

  public function boardSprints(int $boardId, int $startAt = 0, int $maxResults = 100): array {
    $this->sprintRequests++;
    return ['values' => [['id' => (string)($boardId + 1000), 'name' => 'Sprint', 'state' => 'active']], 'isLast' => true];
  }

  public function projectStatuses(string $projectKey): array {
    $this->filterRequests++;
    return [['name' => 'Task', 'statuses' => [['name' => 'Open']]]];
  }

  public function statuses(): array {
    return [['name' => 'Global Open']];
  }

  public function issueTypes(): array {
    return [['name' => 'Global Task']];
  }

  public function project(string $projectKey): array {
    return ['id' => $projectKey === 'AP' ? '100' : '200'];
  }

  public function projectPriorities(string $projectId, int $startAt = 0, int $maxResults = 100): array {
    $this->priorityRequests++;
    if ($projectId === '100') {
      return $startAt === 0
        ? ['values' => [['name' => 'High']], 'isLast' => false]
        : ['values' => [['name' => 'Low']], 'isLast' => true];
    }
    return ['values' => [['name' => 'Critical']], 'isLast' => true];
  }

  public function allPriorities(int $startAt = 0, int $maxResults = 100): array {
    $this->priorityRequests++;
    return ['values' => [['name' => 'Site Priority']], 'isLast' => true];
  }

  public function users(string $query = ''): array {
    return [['accountId' => 'global-user', 'displayName' => 'Global User']];
  }

  public function assignableUsers(string $projectKey): array {
    return [['accountId' => $projectKey . '-user', 'displayName' => $projectKey . ' User']];
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
$settings['projectKey'] = '';
$settings['boardId'] = '';
Settings::save($settings);
check((new JqlBuilder())->current() === '', 'Any project and no board selected must leave the search unrestricted.');
$settings['boardId'] = '12';
Settings::save($settings);
check((new JqlBuilder())->current() === '', 'Selecting a board alone must not constrain ticket search.');
$settings['projectKey'] = 'AP';
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
$global = $data->filterOptions('');
check($global['priorityScope'] === 'global' && $global['status'] === ['Global Open'] && $global['type'] === ['Global Task'] && $global['priority'] === ['Site Priority'] && $global['assignee'] === ['global-user'], 'Any project must load global filter choices.');
check($data->filterOptions('') === $global, 'Any project filter choices must be cached.');
foreach (['AP', 'BP', 'CP', 'AP', 'EMPTY', 'EMPTY'] as $key) {
  if (!BoardCache::has($key)) {
    $data->boards($key);
  }
}
check($fake->boardRequests === 4, 'Each project board list must be fetched once, including an empty list.');
check(count(BoardCache::load('AP')) === 1 && count(BoardCache::load('BP')) === 1 && count(BoardCache::load('CP')) === 1, 'Boards for multiple projects must remain cached.');
check(BoardCache::has('EMPTY') && BoardCache::load('EMPTY') === [], 'An empty board response must remain cached.');
foreach (['101', '102', '103', '101'] as $id) {
  if (!SprintCache::has($id)) {
    $data->sprints($id);
  }
}
check($fake->sprintRequests === 3 && count(SprintCache::load('101')) === 1, 'Each board sprint list must be fetched once.');
$options = $data->filterOptions('AP');
check($options['_loaded'] && $options['assignee'] === ['AP-user'] && $options['status'] === ['Open'], 'Project filter options must be loaded.');
check($options['priorityScope'] === 'project' && $options['priority'] === ['High', 'Low'] && $fake->priorityRequests === 3, 'Project priorities must include every page.');
$settings['boardId'] = '';
Settings::save($settings);
check(FilterCache::load('AP')['assignee'] === ['AP-user'], 'Project filter options must remain available without a board.');
$settings['boardId'] = '102';
Settings::save($settings);
check($data->filterOptions('AP')['assignee'] === ['AP-user'] && $fake->filterRequests === 1, 'Changing boards must reuse project filter options.');
$data->filterOptions('BP');
check($fake->filterRequests === 2 && FilterCache::load('BP')['assignee'] === ['BP-user'], 'Different projects need separate filter choices.');
check(FilterCache::load('BP')['priority'] === ['Critical'], 'Different projects must have their own priorities.');
$data->filterOptions('AP', true);
check($fake->filterRequests === 3, 'Explicit reload must refresh filter choices.');
AppData::saveJson('filter-options.json', ['CP|103' => ['_loaded' => true, 'assignee' => ['CP-user'], 'priority' => ['Site only']]]);
check(FilterCache::load('CP')['assignee'] === ['CP-user'], 'Previously board-keyed filter choices must remain usable.');
check(FilterCache::load('CP')['priorityScope'] === '', 'Old site-wide priority lists must be marked for refresh.');
check($data->filterOptions('CP')['priority'] === ['Critical'], 'Old site-wide priorities must be replaced with project priorities.');
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
check(!BoardCache::has('AP') && !SprintCache::has('101'), 'Clearing caches must remove board and sprint choices.');
check(TicketCache::load() === [], 'Ticket cache was not cleared.');
check($data->cachedIssueTypes('AP') === [], 'Issue type cache was not cleared.');
foreach (glob($testHome . '/.majira/*') ?: [] as $file) {
  unlink($file);
}
rmdir($testHome . '/.majira');
rmdir($testHome);
echo "JiraData OK\n";
