<?php

namespace MAJIRA\App {

  /** Keep field-setting mutations inside this test process. */
  class Settings {

    public static bool $configured = false;
    public static array $data = [
      'fields' => ['key', 'summary', 'status'],
      'fieldOptions' => [['id' => 'customfield_10000', 'name' => 'Story points']],
    ];

    public static function load(): array {
      return self::$data;
    }

    public static function save(array $settings): bool {
      self::$data = $settings;
      return true;
    }

    public static function isConfigured(?array $settings = null): bool {
      return self::$configured;
    }

  }

}

namespace {

  define('APP_DIR', dirname(__DIR__));
  define('APP_NAMESPACE', 'MAJIRA');
  require_once APP_DIR . '/SPTK/App.php';

  use MAJIRA\App\{Controller, JiraData, Settings};
  use MAJIRA\Jira\Client;
  use SPTK\App;
  use SPTK\Core\{Style, Window};
  use SPTK\Rendering\Font;
  use SPTK\SDLWrapper\{SDL, TTF};
  use SPTK\XmlParser\ScreenParser;

  /** Fail the shared field selection check with a useful message. */
  function fieldSaveCheck(bool $condition, string $message): void {
    if (!$condition) {
      throw new \RuntimeException($message);
    }
  }

  /** Return a fresh catalog without contacting Jira. */
  final class FieldCatalogClient extends Client {

    public function __construct() {
      parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
    }

    public function fields(): array {
      return [
        ['id' => 'customfield_10000', 'name' => 'Story points'],
        ['id' => 'customfield_10001', 'name' => 'Customer impact'],
      ];
    }

  }

  /** Supply the fake catalog to the Settings controller. */
  final class FieldCatalogData extends JiraData {

    public function client(): Client {
      return new FieldCatalogClient();
    }

  }

  putenv('SDL_VIDEODRIVER=dummy');
  $sdl = new SDL();
  $sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
  $ttf = new TTF();
  fieldSaveCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
  $font = new Font($ttf);
  $font->open('LiberationMono-Bold', 17);
  $app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
  (new \ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
  (new \ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
  (new \ReflectionProperty(App::class, 'font'))->setValue($app, $font);
  $parser = new ScreenParser();
  $screens = [
    $parser->parse('settings.xml', new Style(), 'settings', 'Settings'),
    $parser->parse('list.xml', new Style(), 'list', 'List'),
    $parser->parse('ticket.xml', new Style(), 'ticket', 'Ticket'),
  ];
  $window = new Window(['title' => 'Field save test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => $screens]);
  foreach ($screens as $screen) {
    $screen->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
  }
  try {
    (new \ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
    (new \ReflectionProperty(Controller::class, 'data'))->setValue(null, new FieldCatalogData());
    (new \ReflectionMethod(Controller::class, 'prepareFieldSettings'))->invoke(null);
    (new \ReflectionMethod(Controller::class, 'prepareTicketTiles'))->invoke(null);
    fieldSaveCheck($screens[2]->layout->findNode('ticket-property-key') === null
      && $screens[2]->layout->findNode('ticket-property-summary') === null
      && $screens[2]->widget('ticket-key') !== null
      && $screens[2]->widget('ticket-summary') !== null, 'Selected Key and Summary should use their existing Ticket widgets without duplicate cards.');
    (new \ReflectionProperty(Controller::class, 'issues'))->setValue(null, [
      ['key' => 'AP-1', 'fields' => ['summary' => 'First', 'status' => ['name' => 'Open'], 'customfield_10000' => 8]],
    ]);
    (new \ReflectionMethod(Controller::class, 'renderTickets'))->invoke(null);
    Settings::$configured = true;
    $screens[0]->widget('settings-fields')->press();
    $list = $screens[0]->widget('settings-fields-list');
    fieldSaveCheck(in_array('customfield_10001', $list->values(), true), 'Opening should load Jira fields into the selector.');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_ESCAPE, 'mod' => 0, 'repeat' => false]]);
    fieldSaveCheck(!isset(Settings::$data['fieldOptions'][1]), 'Escape should discard the newly loaded Jira catalog.');
    Settings::$configured = false;
    $screens[0]->widget('settings-fields')->press();
    $list = $screens[0]->widget('settings-fields-list');
    $list->setCursorValue('customfield_10000');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_SPACE, 'mod' => 0, 'repeat' => false]]);
    fieldSaveCheck($list->getValue() === ['key', 'summary', 'status', 'customfield_10000']
      && $list->activeValue() === 'customfield_10000', 'A newly selected field should move to the end of the selected group and retain its cursor.');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_UP, 'mod' => SDL::MOD_SHIFT, 'repeat' => false]]);
    fieldSaveCheck($list->getValue() === ['key', 'summary', 'customfield_10000', 'status'], 'Shift+Up should reorder selected fields within their group.');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_DOWN, 'mod' => SDL::MOD_SHIFT, 'repeat' => false]]);
    fieldSaveCheck($list->getValue() === ['key', 'summary', 'status', 'customfield_10000'], 'Shift+Down should restore the selected field order.');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_DOWN, 'mod' => SDL::MOD_SHIFT, 'repeat' => false]]);
    fieldSaveCheck(array_slice($list->values(), 0, 4) === ['key', 'summary', 'status', 'customfield_10000'], 'Reordering should keep selected fields above available fields.');
    $list->setCursorValue('summary');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_SPACE, 'mod' => 0, 'repeat' => false]]);
    fieldSaveCheck($list->getValue() === ['key', 'status', 'customfield_10000']
      && $list->activeValue() === 'summary'
      && $list->cursorPosition() === 3
      && $list->values()[3] === 'summary', 'A deselected field should move just below the selected fields and retain its cursor.');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RETURN, 'mod' => 0, 'repeat' => false]]);
    fieldSaveCheck(Settings::$data['fields'] === ['key', 'status', 'customfield_10000'], 'Saving should retain the selected field order.');
    fieldSaveCheck($screens[1]->widget('tickets')->header() === ['Key', 'Status', 'Story points'], 'List columns should follow the shared setting.');
    fieldSaveCheck($screens[1]->widget('tickets')->rowValues(0) === ['AP-1', 'Open', '8'], 'List cells should use the matching issue fields.');
    fieldSaveCheck($screens[2]->widget('ticket-customfield_10000') !== null && $screens[2]->widget('ticket-summary') !== null, 'Ticket cards should follow the shared setting while retaining the title input.');
    fieldSaveCheck($screens[2]->layout->findNode('ticket-property-key') === null, 'Selected Key should not create a duplicate property card.');
    fieldSaveCheck($screens[2]->layout->findNode('ticket-property-summary') === null, 'Unselected property cards should be removed.');
    $shownIds = array_map(static fn($leaf): ?string => $leaf->instance()->id(), $screens[0]->layout->leaves());
    fieldSaveCheck(in_array('jira-site', $shownIds, true) && !in_array('settings-fields-list', $shownIds, true), 'Saving should restore the connection controls.');
    $screens[0]->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RETURN, 'mod' => 0, 'repeat' => false]]);
    fieldSaveCheck($screens[0]->selectedLeaf()?->instance() === $screens[0]->widget('settings-fields'), 'Saving should select the Fields button.');
    echo "Field Settings save checks passed.\n";
  } finally {
    $window->close();
  }

}
