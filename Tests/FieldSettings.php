<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{Controller, FieldConfiguration, JiraData, Settings};
use MAJIRA\Jira\Client;
use SPTK\App;
use SPTK\Core\{Style, Window};
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\List\ListView;
use SPTK\XmlParser\ScreenParser;

/** Fail a Settings field-selector interaction check. */
function settingsFieldCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Return the shown leaf of a widget, or null while it is hidden. */
function settingsShownLeaf(SPTK\Core\Screen $screen, string $id): ?SPTK\Layout\LayoutLeaf {
  foreach ($screen->layout->leaves() as $leaf) {
    if ($leaf->instance()->id() === $id) {
      return $leaf;
    }
  }
  return null;
}

/** Serve field metadata without depending on a live Jira account. */
final class SettingsFieldClient extends Client {

  public function __construct() {
    parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
  }

  public function fields(): array {
    return [['id' => 'customfield_10001', 'name' => 'Customer impact']];
  }

}

/** Provide the fake Jira catalog to the Settings interaction test. */
final class SettingsFieldData extends JiraData {

  public function client(): Client {
    return new SettingsFieldClient();
  }

}

$saved = Settings::load();
putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
settingsFieldCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$screen = (new ScreenParser())->parse('settings.xml', new Style(), 'settings', 'Settings');
$window = new Window(['title' => 'Fields test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => [$screen]]);
$screen->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, new SettingsFieldData());
  (new ReflectionMethod(Controller::class, 'prepareFieldSettings'))->invoke(null);
  $leftGrid = $screen->layout->findNode('settings-left')->grid();
  $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => ord('f'), 'mod' => 0, 'repeat' => false]]);
  $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_UP, 'key' => (object)['key' => ord('f'), 'mod' => 0, 'repeat' => false]]);
  $list = $screen->widget('settings-fields-list');
  settingsFieldCheck($list instanceof ListView && settingsShownLeaf($screen, 'jira-site') === null, 'F should replace the connection controls with the field selector.');
  $screen->measureGrid(new SPTK\Layout\Tile(0, 0, $screen->layout->grid()->width, $screen->layout->grid()->height));
  $panelGrid = settingsShownLeaf($screen, 'settings-fields-list')->grid();
  settingsFieldCheck($panelGrid->width === $leftGrid->width && $panelGrid->height === $leftGrid->height, 'The selector should occupy the whole Settings tile.');
  settingsFieldCheck($screen->activeLeaf()?->instance() === $list, 'The selector should be active on opening.');
  settingsFieldCheck($list->getValue() === FieldConfiguration::selected($saved), 'The selector should show the saved order and selection.');
  $list->setValue(['key']);
  $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_ESCAPE, 'mod' => 0, 'repeat' => false]]);
  settingsFieldCheck(settingsShownLeaf($screen, 'jira-site') !== null && settingsShownLeaf($screen, 'settings-fields-list') === null, 'Escape should restore the Settings controls.');
  settingsFieldCheck($screen->selectedLeaf()?->instance() === $screen->widget('settings-fields'), 'Escape should select the Fields button.');
  settingsFieldCheck(Settings::load() === $saved, 'Escape should leave saved fields unchanged.');
  echo "Field Settings checks passed.\n";
} finally {
  $window->close();
}
