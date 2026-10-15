<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{BoardState, JiraData, TicketAgileFields};
use MAJIRA\Jira\Client;

/** Serve boards, sprints, and board configuration while recording Agile writes. */
final class AgileClient extends Client {

  public array $writes = [];

  public function __construct() {
    parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
  }

  public function boards(string $projectKey = '', int $startAt = 0, int $maxResults = 100): array {
    return ['isLast' => true, 'values' => [['id' => 1, 'name' => 'Alpha'], ['id' => 2, 'name' => 'Team']]];
  }

  public function boardSprints(int $boardId, int $startAt = 0, int $maxResults = 100): array {
    return ['isLast' => true, 'values' => [
      ['id' => 10, 'name' => 'Old', 'state' => 'closed'],
      ['id' => 11, 'name' => 'Now', 'state' => 'active'],
      ['id' => 12, 'name' => 'Next', 'state' => 'future'],
    ]];
  }

  public function boardConfiguration(int $boardId): array {
    return ['estimation' => ['type' => 'field', 'field' => ['fieldId' => 'customfield_10026']]];
  }

  public function moveIssuesToSprint(int $sprintId, array $issueKeys): array {
    $this->writes[] = ['sprint', $sprintId, $issueKeys];
    return [];
  }

  public function moveIssuesToBacklog(array $issueKeys): array {
    $this->writes[] = ['backlog', $issueKeys];
    return [];
  }

  public function setIssueEstimation(string $issueKey, int $boardId, ?string $value): array {
    $this->writes[] = ['estimate', $issueKey, $boardId, $value];
    return [];
  }

}

/** Use the fake client without loading saved connection settings. */
final class AgileData extends JiraData {

  public function __construct(private AgileClient $fake) {
  }

  public function client(): Client {
    return $this->fake;
  }

}

/** Fail with a readable message. */
function agileCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Expect the editor value to be rejected. */
function agileRejects(TicketAgileFields $agile, array $model, string $value): void {
  try {
    $agile->saveValue($model, $value);
  } catch (InvalidArgumentException $expected) {
    return;
  }
  throw new RuntimeException('Invalid value was accepted: ' . $value);
}

$testHome = sys_get_temp_dir() . '/majira-test-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
$client = new AgileClient();
$agile = new TicketAgileFields(new AgileData($client));
$issue = ['key' => 'AP-1', 'fields' => [
  'customfield_10020' => [['id' => 10, 'name' => 'Old', 'state' => 'closed'], ['id' => 11, 'name' => 'Now', 'state' => 'active']],
  'customfield_10026' => 3.0,
]];
agileCheck(TicketAgileFields::isSprint(['schema' => ['type' => 'array', 'custom' => 'com.pyxis.greenhopper.jira:gh-sprint']]), 'The Sprint schema should be detected.');
agileCheck(!TicketAgileFields::isSprint(['schema' => ['type' => 'number']]), 'Other fields are not sprints.');
agileCheck($agile->boardId('AP') === '1', 'Without a saved board, the first project board is used.');
BoardState::save(['boardId' => '2']);
agileCheck($agile->boardId('AP') === '2', 'The Board screen board should win when the project has it.');
BoardState::save(['boardId' => '99']);
agileCheck($agile->boardId('AP') === '1', 'A board from another project should be ignored.');

$sprint = $agile->sprintModel($issue, 'customfield_10020', 'Sprint', '2');
agileCheck(array_column($sprint['options'], 'value') === ['', '11', '12'], 'Sprint choices should be Backlog plus open sprints.');
agileCheck($sprint['value'] === '11', 'The ticket\'s open sprint should be selected.');
agileRejects($agile, $sprint, '10');
$agile->save('AP-1', $sprint, $agile->saveValue($sprint, '12'));
$agile->save('AP-1', $sprint, $agile->saveValue($sprint, ''));
agileCheck($client->writes === [['sprint', 12, ['AP-1']], ['backlog', ['AP-1']]], 'Sprint saves should move the issue.');

agileCheck($agile->estimateModel($issue, 'customfield_10016', 'Other', '2') === null, 'Only the board estimation field uses the estimate API.');
$estimate = $agile->estimateModel($issue, 'customfield_10026', 'Story Points', '2');
agileCheck($estimate['kind'] === 'estimate' && $estimate['value'] === '3', 'Story points should open with the current value.');
agileRejects($agile, $estimate, '3x');
$client->writes = [];
$agile->save('AP-1', $estimate, $agile->saveValue($estimate, ' 5 '));
$agile->save('AP-1', $estimate, $agile->saveValue($estimate, ''));
agileCheck($client->writes === [['estimate', 'AP-1', 2, '5'], ['estimate', 'AP-1', 2, null]], 'Estimates should be written for the chosen board.');
echo "Ticket agile field checks passed.\n";
