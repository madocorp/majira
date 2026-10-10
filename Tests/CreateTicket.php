<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{Controller, JiraData, Settings};
use SPTK\App;
use SPTK\Core\{AppData, Style, Window};
use SPTK\Events\EventContext;
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\Button\Button;
use SPTK\XmlParser\ScreenParser;

/** Fail a create-screen interaction check. */
function createCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Build a keyboard event for the create screen. */
function createKey(int $type, int $key): object {
  return (object)['type' => $type, 'key' => (object)['key' => $key, 'mod' => 0, 'repeat' => false]];
}

$testHome = sys_get_temp_dir() . '/majira-create-ticket-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
Settings::save(['projectKey' => 'AP']);
AppData::saveJson('issue-types.json', ['AP' => [['id' => '100', 'name' => 'Task']]]);
putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
createCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$list = (new ScreenParser())->parse('list.xml', new Style(), 'list', 'List');
$create = (new ScreenParser())->parse('create.xml', new Style(), 'create', 'Create');
$window = new Window(['title' => 'Create ticket test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => [$list, $create]]);
$create->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, new JiraData());
  $buttons = array_values(array_filter(array_map(static fn($leaf) => $leaf->instance(), $create->layout->leaves()), static fn($widget) => $widget instanceof Button));
  createCheck(array_map(static fn(Button $button) => $button->hotkey(), $buttons) === ['c', 'escape'], 'Create screen needs only C Create and Esc Cancel buttons.');
  Controller::newTicket(new EventContext('activate'));
  createCheck($window->currentScreenId() === 'create' && $create->widget('create-types')->values() === ['100'], 'Cached issue types must appear without manual reload.');
  foreach (['create-types', 'create-description'] as $id) {
    $leaf = current(array_filter($create->layout->leaves(), static fn($leaf) => $leaf->instance() === $create->widget($id)));
    $create->activateLeaf($leaf);
    $create->handleEvent(createKey(SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_ESCAPE));
    createCheck($window->currentScreenId() === 'create' && $create->activeLeaf() === null, 'Esc must first release the active ' . $id . ' widget.');
    $create->handleEvent(createKey(SDL::SDL_EVENT_KEY_DOWN, SDL::KEY_ESCAPE));
    createCheck($window->currentScreenId() === 'list', 'Esc must cancel after releasing ' . $id . '.');
    Controller::newTicket(new EventContext('activate'));
  }
  $summaryLeaf = current(array_filter($create->layout->leaves(), static fn($leaf) => $leaf->instance() === $create->widget('create-summary')));
  $create->selectLeaf($summaryLeaf);
  $create->handleEvent(createKey(SDL::SDL_EVENT_KEY_DOWN, ord('c')));
  $create->handleEvent(createKey(SDL::SDL_EVENT_KEY_UP, ord('c')));
  createCheck($create->statusBar->kind() === 'error' && str_contains($create->statusBar->text(), 'enter a summary'), 'C must run the Create action.');
  echo "Create ticket checks passed.\n";
} finally {
  $window->close();
}
