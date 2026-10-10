<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{Controller, JiraData};
use SPTK\App;
use SPTK\Core\{Style, Window};
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\XmlParser\{ScreenParser, ScreenSelector};

/** Fail a screen shortcut check. */
function shortcutCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Build a keydown event for the current screen. */
function shortcutKey(int $key, int $mod = 0): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => $key, 'mod' => $mod, 'repeat' => false]];
}

/** Find a measured widget tile by id. */
function shortcutLeaf(SPTK\Core\Screen $screen, string $id): SPTK\Layout\LayoutLeaf {
  foreach ($screen->layout->leaves() as $leaf) {
    if ($leaf->instance()->id() === $id) {
      return $leaf;
    }
  }
  throw new RuntimeException('Missing widget: ' . $id);
}

$testHome = sys_get_temp_dir() . '/majira-shortcuts-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
shortcutCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$style = new Style();
$parser = new ScreenParser();
$screens = [];
foreach (['list' => 'List', 'filters' => 'Filters', 'board' => 'Board', 'ticket' => 'Ticket', 'settings' => 'Settings'] as $id => $title) {
  $screens[$id] = $parser->parse($id . '.xml', $style, $id, $title);
  if (in_array($id, ['list', 'board', 'ticket'], true)) {
    $events = (new ReflectionProperty(SPTK\Core\Screen::class, 'events'))->getValue($screens[$id]);
    $keys = array_column($events, 'key');
    $refresh = ['list' => 'refreshTickets', 'board' => 'refreshBoard', 'ticket' => 'refreshTicket'][$id];
    shortcutCheck(!in_array('f5', $keys, true) && !in_array('ctrl+r', $keys, true), $id . ' must reserve F5 for Settings and have no Ctrl+R binding.');
    shortcutCheck(count(array_filter($events, static fn($event) => $event->key === 'r' && $event->action === Controller::class . '::' . $refresh)) === 1, $id . ' must use its own R refresh action.');
  }
}
(new ScreenSelector())->install(array_values($screens), 'list, filters, board, ticket, settings', $style);
$window = new Window(['title' => 'Shortcut test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => array_values($screens)]);
$data = new class extends JiraData {
  public int $searches = 0;
  public int $ticketLoads = 0;

  /** Count search requests without contacting Jira. */
  public function tickets(string $jql, bool $more = false): array {
    $this->searches++;
    return ['issues' => [], 'meta' => ['hasMore' => false]];
  }

  /** Count detail refreshes without contacting Jira. */
  public function ticket(string $key, bool $refresh = false): array {
    $this->ticketLoads++;
    return ['key' => $key, 'fields' => ['summary' => 'Loaded ticket', 'description' => null]];
  }
};
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, $data);
  (new ReflectionMethod(Controller::class, 'prepareTicketTiles'))->invoke(null);
  $window->handleEvent(shortcutKey(SDL::KEY_F5));
  shortcutCheck($window->currentScreenId() === 'settings' && $data->searches === 0, 'F5 must open Settings without searching Jira.');
  $logo = shortcutLeaf($screens['settings'], 'settings-logo')->grid();
  $site = shortcutLeaf($screens['settings'], 'jira-site')->grid();
  $test = shortcutLeaf($screens['settings'], 'settings-test')->grid();
  $save = shortcutLeaf($screens['settings'], 'settings-save')->grid();
  $clear = shortcutLeaf($screens['settings'], 'settings-clear')->grid();
  $quit = shortcutLeaf($screens['settings'], 'settings-quit')->grid();
  shortcutCheck($logo->x === 0 && $logo->y === 2 && $logo->width > 0 && $logo->height > 0, 'Settings logo must sit in the top left layout area.');
  $logoBox = new SPTK\Layout\Tile(0, 0, $logo->width * $font->cellWidth(), $logo->height * $font->cellHeight());
  $fittedLogo = $screens['settings']->widget('settings-logo')->destination($logoBox);
  shortcutCheck($logo->width === $screens['settings']->layout->findNode('settings-left')->grid()->width, 'Settings logo box must span the left column.');
  shortcutCheck($fittedLogo->width === $fittedLogo->height && $fittedLogo->width === min($logoBox->width, $logoBox->height) && $fittedLogo->x === intdiv($logoBox->width - $fittedLogo->width, 2) && $fittedLogo->y === intdiv($logoBox->height - $fittedLogo->height, 2), 'Settings logo must fit and remain centered within its box.');
  shortcutCheck($site->y > $logo->y + $logo->height && $test->y > $site->y && $save->y > $test->y && $clear->y > $save->y + 1 && $quit->y > $clear->y, 'Settings controls must follow the logo and leave space before Clear and Quit.');
  $about = shortcutLeaf($screens['settings'], 'settings-about')->grid();
  $license = shortcutLeaf($screens['settings'], 'settings-unlicense')->grid();
  $status = shortcutLeaf($screens['settings'], 'status')->grid();
  $left = $screens['settings']->layout->findNode('settings-left')->grid();
  shortcutCheck($about->x > $site->x && $about->y < $license->y && $screens['settings']->widget('settings-help') === null, 'About and Unlicense must stack without Help.');
  shortcutCheck($status->y === $left->y + $left->height + 1, 'A separator must sit above the Settings status bar.');
  shortcutCheck(array_map(static fn($id) => $screens['settings']->widget($id)->hotkey(), ['settings-test', 'settings-save', 'settings-clear', 'settings-quit']) === ['t', 's', 'r', 'q'], 'Settings actions need T, S, R, and Q hotkeys.');
  $window->setCurrentScreenId('list');
  $jqlLeaf = current(array_filter($screens['list']->layout->leaves(), static fn($leaf) => $leaf->instance() === $screens['list']->widget('jql')));
  $screens['list']->activateLeaf($jqlLeaf);
  $window->handleEvent(shortcutKey(ord('r')));
  shortcutCheck($data->searches === 0, 'Typing R into active JQL must not refresh the List.');
  $screens['list']->release('accept');
  $window->handleEvent(shortcutKey(ord('r')));
  shortcutCheck($window->currentScreenId() === 'list' && $data->searches === 1, 'R must refresh the List without switching screens.');
  (new ReflectionProperty(Controller::class, 'ticketKey'))->setValue(null, 'AP-1234');
  $window->setCurrentScreenId('ticket');
  $window->handleEvent(shortcutKey(ord('r')));
  shortcutCheck($window->currentScreenId() === 'ticket' && $data->ticketLoads === 1 && $data->searches === 1, 'R on Ticket must reload only the current ticket.');
  $titleLeaf = current(array_filter($screens['ticket']->layout->leaves(), static fn($leaf) => $leaf->instance() === $screens['ticket']->widget('ticket-summary')));
  $screens['ticket']->activateLeaf($titleLeaf);
  $window->handleEvent(shortcutKey(ord('r')));
  shortcutCheck($data->ticketLoads === 1, 'Typing R into the active ticket title must not refresh it.');
  $screens['ticket']->release('accept');
  $attachmentLeaf = current(array_filter($screens['ticket']->layout->leaves(), static fn($leaf) => $leaf->instance() === $screens['ticket']->widget('ticket-attachments')));
  $screens['ticket']->activateLeaf($attachmentLeaf);
  $window->handleEvent(shortcutKey(ord('r')));
  shortcutCheck($data->ticketLoads === 2 && $data->searches === 1, 'R on an active ticket table must still reload only the current ticket.');
  echo "Screen shortcut checks passed.\n";
} finally {
  $window->close();
}
