<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{Controller, JiraData, TicketFieldEditModel};
use MAJIRA\Jira\Client;
use SPTK\App;
use SPTK\Core\{Style, Window};
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\DateSelector\DateSelector;
use SPTK\Widgets\Input\Input;
use SPTK\Widgets\List\ListView;
use SPTK\XmlParser\ScreenParser;

/** Capture Jira property writes while providing realistic edit metadata. */
final class PropertyClient extends Client {

  public array $writes = [];

  public function __construct(public array $issue) {
    parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
  }

  public function editMeta(string $issueKey): array {
    return ['fields' => [
      'priority' => ['name' => 'Priority', 'required' => true, 'schema' => ['type' => 'priority'], 'operations' => ['set'], 'allowedValues' => [
        ['id' => '1', 'name' => 'High'], ['id' => '2', 'name' => 'Low'],
      ]],
      'labels' => ['name' => 'Labels', 'schema' => ['type' => 'array', 'items' => 'string'], 'operations' => ['set']],
      'duedate' => ['name' => 'Due date', 'schema' => ['type' => 'date'], 'operations' => ['set']],
      'assignee' => ['name' => 'Assignee', 'schema' => ['type' => 'user'], 'operations' => ['set']],
    ]];
  }

  public function assignableUsersForIssue(string $issueKey): array {
    return [['accountId' => 'ada', 'displayName' => 'Ada'], ['accountId' => 'bob', 'displayName' => 'Bob']];
  }

  public function transitions(string $issueKey): array {
    return ['transitions' => [['id' => '31', 'name' => 'In Progress']]];
  }

  public function updateIssueFields(string $issueKey, array $fields): array {
    $this->writes[] = $fields;
    $this->issue['fields'] = array_replace($this->issue['fields'], $fields);
    return [];
  }

  public function transitionIssue(string $issueKey, string $transitionId): array {
    $this->writes[] = ['transition' => $transitionId];
    $this->issue['fields']['status'] = ['name' => 'In Progress'];
    return [];
  }

}

/** Serve the fake client's latest issue after each write. */
final class PropertyData extends JiraData {

  public function __construct(private PropertyClient $fake) {
  }

  public function client(): Client {
    return $this->fake;
  }

  public function ticket(string $key, bool $refresh = false): array {
    return $this->fake->issue;
  }

}

/** Fail the keyboard interaction check with a useful message. */
function propertyCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Build one SDL keydown event. */
function propertyKey(int $key, int $mod = 0): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => $key, 'mod' => $mod, 'repeat' => false]];
}

$issue = ['key' => 'AP-1', 'fields' => [
  'summary' => 'Property ticket', 'status' => ['name' => 'Open'], 'issuetype' => ['name' => 'Task'],
  'priority' => ['id' => '1', 'name' => 'High'], 'assignee' => ['accountId' => 'ada', 'displayName' => 'Ada'],
  'labels' => ['one'], 'duedate' => '2026-10-12',
]];
$client = new PropertyClient($issue);
putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
propertyCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$screen = (new ScreenParser())->parse('ticket.xml', new Style(), 'ticket', 'Ticket');
$window = new Window(['title' => 'Property test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => [$screen]]);
$screen->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, new PropertyData($client));
  (new ReflectionProperty(Controller::class, 'ticket'))->setValue(null, $issue);
  (new ReflectionProperty(Controller::class, 'ticketKey'))->setValue(null, 'AP-1');
  (new ReflectionMethod(Controller::class, 'prepareTicketTiles'))->invoke(null);
  (new ReflectionMethod(Controller::class, 'renderTicket'))->invoke(null);

  $open = static function(string $field) use ($screen): mixed {
    $summary = $screen->widget('ticket-' . $field);
    foreach ($screen->layout->leaves() as $leaf) {
      if ($leaf->instance() === $summary) {
        $screen->selectLeaf($leaf);
        break;
      }
    }
    $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
    return $screen->widget('ticket-property-editor');
  };

  $assigneeBefore = $screen->layout->findNode('ticket-property-assignee')->grid()->y;
  $editor = $open('priority');
  propertyCheck($editor instanceof ListView && $editor->getValue() === '1', 'Priority should open a list at its current value.');
  propertyCheck($screen->layout->findNode('ticket-property-assignee')->grid()->y > $assigneeBefore, 'The editor should enlarge its property tile.');
  $editor->setValue('2');
  $screen->handleEvent(propertyKey(SDL::KEY_ESCAPE));
  propertyCheck($client->writes === [] && $screen->widget('ticket-priority') !== null, 'Escape should cancel the inline choice editor.');
  propertyCheck($screen->layout->findNode('ticket-property-assignee')->grid()->y === $assigneeBefore, 'Escape should restore the compact property layout.');

  $editor = $open('priority');
  $editor->setValue('2');
  $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
  propertyCheck($client->writes === [['priority' => ['id' => '2']]], 'Return should save the selected Jira choice.');
  propertyCheck($screen->layout->findNode('ticket-property-assignee')->grid()->y === $assigneeBefore, 'Saving should restore the compact property layout.');

  $editor = $open('labels');
  propertyCheck($editor instanceof Input && $editor->getValue() === 'one', 'String lists should open an Input with current values.');
  $editor->setValue('one, two');
  $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
  propertyCheck(end($client->writes) === ['labels' => ['one', 'two']], 'Labels should save as a string array.');

  $labelsBefore = $screen->layout->findNode('ticket-property-labels')->grid()->y
    - $screen->layout->findNode('ticket-property-duedate')->grid()->y;
  $editor = $open('duedate');
  propertyCheck($editor instanceof DateSelector && $editor->getValue() === '2026-10-12', 'Dates should open the date picker.');
  $editor->setValue('2026-10-20');
  $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
  propertyCheck(end($client->writes) === ['duedate' => '2026-10-20'], 'Date picker changes should save.');
  propertyCheck($screen->layout->findNode('ticket-property-labels')->grid()->y
    - $screen->layout->findNode('ticket-property-duedate')->grid()->y === $labelsBefore, 'Saving a date should restore the compact property layout.');

  $editor = $open('duedate');
  $editor->handleInput(propertyKey(SDL::KEY_DELETE));
  $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
  propertyCheck($screen->statusBar->kind() === 'error' && end($client->writes) === ['duedate' => '2026-10-20'], 'An incomplete date must not be saved.');
  $screen->handleEvent(propertyKey(SDL::KEY_ESCAPE));
  $screen->handleEvent(propertyKey(SDL::KEY_ESCAPE));

  $open('duedate');
  $screen->handleEvent(propertyKey(SDL::KEY_DELETE, SDL::MOD_CTRL));
  propertyCheck(end($client->writes) === ['duedate' => null], 'Ctrl+Delete should clear an optional date.');

  $editor = $open('assignee');
  propertyCheck($editor instanceof ListView && $editor->getValue() === 'ada', 'Users should open a list of assignable accounts.');
  $editor->setValue('bob');
  $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
  propertyCheck(end($client->writes) === ['assignee' => ['accountId' => 'bob']], 'User choices should save account IDs.');

  $editor = $open('status');
  propertyCheck($editor instanceof ListView, 'Status should open the available transitions.');
  $screen->handleEvent(propertyKey(SDL::KEY_RETURN));
  propertyCheck(end($client->writes) === ['transition' => '31'], 'Status should use a Jira workflow transition.');

  $open('created');
  propertyCheck($screen->statusBar->kind() === 'error' && $screen->widget('ticket-property-editor') === null, 'Jira timestamps should report a non-editable error.');

  $model = new TicketFieldEditModel();
  foreach (['abc', '12x'] as $bad) {
    try {
      $model->saveValue(['kind' => 'number'], $bad);
      throw new RuntimeException('Invalid number was accepted.');
    } catch (InvalidArgumentException $expected) {
      propertyCheck(str_contains($expected->getMessage(), 'number'), 'Invalid numbers need a clear error.');
    }
  }
  echo "Ticket property checks passed.\n";
} finally {
  $window->close();
}
