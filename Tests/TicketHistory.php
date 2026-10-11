<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{Controller, JiraData, TicketHistory};
use MAJIRA\Jira\Adf;
use SPTK\App;
use SPTK\Core\{AppData, Color, Style, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\Tile;
use SPTK\Rendering\{Font, Grid, GridWriter};
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\XmlParser\ScreenParser;

/** Fail the ticket history check with a useful message. */
function historyCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Build a keydown event for the active History list. */
function historyKey(int $key): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => $key, 'mod' => 0, 'repeat' => false]];
}

$testHome = sys_get_temp_dir() . '/majira-ticket-history-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
$first = ['key' => 'AP-1234', 'fields' => [
  'summary' => 'First title',
  'description' => Adf::fromMarkdown('Original description'),
  'comment' => ['comments' => [
    ['id' => '42', 'author' => ['displayName' => 'Ada', 'accountId' => 'ada'], 'created' => '2026-10-10T12:34:56+00:00', 'body' => Adf::fromMarkdown('A comment')],
  ]],
], '_majiraRelatedFieldsLoaded' => true];
$second = ['key' => 'AP-5678', 'fields' => ['summary' => 'Second title', 'description' => null], '_majiraRelatedFieldsLoaded' => true];
AppData::saveJson('ticket-details.json', ['AP-1234' => $first, 'AP-5678' => $second]);
TicketHistory::add($first);
TicketHistory::add($second);
TicketHistory::add($first);
historyCheck(array_column(TicketHistory::load(), 'key') === ['AP-1234', 'AP-5678'], 'History must be newest first without duplicates.');

putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
historyCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$screen = (new ScreenParser())->parse('ticket.xml', new Style(), 'ticket', 'Ticket');
$window = new Window(['title' => 'Ticket History test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => [$screen]]);
$screen->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, new JiraData());
  (new ReflectionMethod(Controller::class, 'prepareTicketTiles'))->invoke(null);
  foreach (['ticket-description' => ['# Heading', '#82b4d8'], 'ticket-comments' => ['**bold**', '#d2bf64']] as $id => [$value, $background]) {
    $editor = $screen->widget($id);
    if ($id === 'ticket-comments') {
      $editor->setText($value);
    } else {
      $editor->setValue($value);
    }
    $grid = new Grid(24, 4);
    $editor->paint(new GridWriter($grid, new Tile(0, 0, 24, 4)));
    historyCheck($grid->cell(0, 1)->bg == Color::from($background), $id . ' should render Markdown colors.');
  }
  (new ReflectionMethod(Controller::class, 'restoreLastTicket'))->invoke(null);
  historyCheck($screen->widget('ticket-summary')->getValue() === 'First title', 'The latest ticket should load first.');
  $comments = $screen->widget('ticket-comments');
  historyCheck($comments->text() === ">>> Ada [2026-10-10 12:34:56]\n\nA comment", 'Comments should use the original highlighted header title.');
  $commentGrid = new Grid(42, 4);
  $comments->paint(new GridWriter($commentGrid, new Tile(0, 0, 42, 4)));
  historyCheck($commentGrid->cell(0, 1)->bg == Color::from('#982461')
    && $commentGrid->cell(41, 1)->bg == Color::from('#982461'), 'The comment header should fill the tile row.');
  $description = $screen->widget('ticket-description');
  foreach ($screen->layout->leaves() as $leaf) {
    if ($leaf->instance() === $description) {
      $screen->activateLeaf($leaf);
      break;
    }
  }
  $description->setValue('Unwanted draft');
  $screen->handleEvent(historyKey(SDL::KEY_ESCAPE));
  historyCheck($description->getValue() === 'Original description' && $screen->activeLeaf() === null, 'Esc must restore the original description and release the editor.');
  historyCheck($screen->statusBar->kind() !== 'error', 'Cancelling a description must not send a Jira update.');
  $screen->handleEvent(historyKey(ord('h')));
  $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_UP, 'key' => (object)['key' => ord('h'), 'mod' => 0, 'repeat' => false]]);
  $list = $screen->widget('ticket-history');
  historyCheck($screen->activeLeaf()?->instance() === $list, 'History must activate its List.');
  historyCheck(array_column($list->items(), 'label') === ['#AP-1234 First title', '#AP-5678 Second title'], 'History labels must show key and title.');
  $screen->handleEvent(historyKey(SDL::KEY_DOWN));
  historyCheck($screen->widget('ticket-summary')->getValue() === 'Second title', 'Moving the cursor should preview the highlighted ticket.');
  historyCheck(array_column(TicketHistory::load(), 'key') === ['AP-1234', 'AP-5678'], 'Preview must preserve history order.');
  $screen->handleEvent(historyKey(SDL::KEY_ESCAPE));
  historyCheck($screen->layout->findNode('ticket-properties-pane') !== null && $screen->widget('ticket-summary')->getValue() === 'First title', 'Esc must restore properties and the original ticket.');
  Controller::showTicketHistory(new EventContext('activate'));
  $screen->handleEvent(historyKey(SDL::KEY_DOWN));
  $screen->handleEvent(historyKey(SDL::KEY_RETURN));
  historyCheck($screen->layout->findNode('ticket-properties-pane') !== null && $screen->widget('ticket-summary')->getValue() === 'Second title', 'Return must open the highlighted ticket and hide History.');
  historyCheck(array_column(TicketHistory::load(), 'key') === ['AP-5678', 'AP-1234'], 'Opening a history ticket must move it to the front.');
  echo "Ticket History checks passed.\n";
} finally {
  $window->close();
}
