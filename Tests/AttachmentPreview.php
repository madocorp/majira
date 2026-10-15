<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{AttachmentPreview, Controller, JiraData};
use SPTK\App;
use SPTK\Core\{Style, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\Tile;
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\{Image\Image, Table\Table, Text\Text};
use SPTK\XmlParser\{ScreenParser, ScreenSelector};

function previewCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function previewKey(int $key): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => $key, 'mod' => 0, 'repeat' => false]];
}

function previewKeyUp(int $key): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_UP, 'key' => (object)['key' => $key, 'mod' => 0, 'repeat' => false]];
}

$directory = sys_get_temp_dir() . '/majira-attachment-preview-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('HOME=' . $directory);
$csv = $directory . '/sample.csv';
file_put_contents($csv, "\xEF\xBB\xBFName,Note\r\nAlpha,\"one,two\"\r\nBeta,\"two\nlines\"\r\n\r\n");
$tsv = $directory . '/sample.tsv';
file_put_contents($tsv, "Name\tNote\nAlpha\t\"one\ttwo\"\n");
$text = $directory . '/sample.sql';
file_put_contents($text, "SELECT * FROM tickets;\n");
previewCheck(AttachmentPreview::kind(['filename' => 'sheet.xls']) === null, 'Legacy XLS is unsupported.');
previewCheck(AttachmentPreview::kind(['filename' => 'sample.png']) === 'image', 'PNG must use image preview.');
previewCheck(AttachmentPreview::kind(['filename' => 'sample.sql']) === 'text', 'SQL must use text preview.');
$csvWidget = AttachmentPreview::widget('csv', $csv, 'sample.csv');
previewCheck($csvWidget instanceof Table && $csvWidget->header() === ['Name', 'Note'], 'CSV header or BOM was parsed incorrectly.');
previewCheck($csvWidget->rowValues(0) === ['Alpha', 'one,two'] && $csvWidget->rowValues(1) === ['Beta', "two\nlines"], 'CSV quoting was parsed incorrectly.');
previewCheck($csvWidget->rowValues(2) === [''], 'A blank CSV row should remain readable.');
$tsvWidget = AttachmentPreview::widget('tsv', $tsv, 'sample.tsv');
previewCheck($tsvWidget instanceof Table && $tsvWidget->rowValues(0) === ['Alpha', "one\ttwo"], 'TSV quoting was parsed incorrectly.');
previewCheck(AttachmentPreview::widget('text', $text, 'sample.sql') instanceof Text, 'Text must use a read-only Text widget.');

putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
previewCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$parser = new ScreenParser();
$ticket = $parser->parse('ticket.xml', new Style(), 'ticket', 'Ticket');
$preview = $parser->parse('attachment-preview.xml', new Style(), 'attachment-preview', 'Attachment preview');
(new ScreenSelector())->install([$ticket, $preview], 'ticket', new Style());
$window = new Window(['title' => 'Attachment preview test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => [$ticket, $preview]]);
$ticket->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
$data = new class($window, $csv, $text, APP_DIR . '/Assets/logo.png') extends JiraData {
  public int $downloads = 0;
  public array $screensDuringLoad = [];
  public array $loadingTexts = [];
  public function __construct(private Window $window, private string $csv, private string $text, private string $image) {}
  public function attachmentPath(array $attachment): string {
    $this->downloads++;
    $this->screensDuringLoad[] = $this->window->currentScreenId();
    $this->loadingTexts[] = $this->window->screen('attachment-preview')->widget('attachment-preview-content') instanceof Text;
    return match (pathinfo($attachment['filename'], PATHINFO_EXTENSION)) {
      'csv' => $this->csv,
      'png' => $this->image,
      default => $this->text,
    };
  }
};
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, $data);
  (new ReflectionMethod(Controller::class, 'prepareAttachmentPreview'))->invoke(null);
  previewCheck(count($preview->layout->leaves()) === 1 && $preview->widget('screen_ticket') === null, 'Preview should have no selector row.');
  (new ReflectionMethod(Controller::class, 'prepareTicketTiles'))->invoke(null);
  $ticketData = ['fields' => ['attachment' => [
    ['filename' => 'sample.csv', 'content' => 'https://example.test/1'],
    ['filename' => 'sample.sql', 'content' => 'https://example.test/2'],
    ['filename' => 'logo.png', 'content' => 'https://example.test/3'],
    ['filename' => 'sheet.xls', 'content' => 'https://example.test/4'],
  ]]];
  (new ReflectionProperty(Controller::class, 'ticket'))->setValue(null, $ticketData);
  (new ReflectionMethod(Controller::class, 'renderTicket'))->invoke(null);
  Controller::expandTicketCard(new EventContext('select', (new ReflectionProperty(Controller::class, 'ticketCardSummaries'))->getValue()['attachments']));
  $table = $ticket->widget('ticket-attachments');
  $table->setCursor(0);
  foreach ($ticket->layout->leaves() as $leaf) {
    if ($leaf->instance() === $table) {
      $ticket->activateLeaf($leaf);
      break;
    }
  }
  $ticket->handleEvent(previewKey(SDL::KEY_RETURN));
  previewCheck($window->currentScreenId() === 'attachment-preview' && $preview->activeLeaf()?->instance() instanceof Table, 'CSV should open the full-window table.');
  previewCheck($data->screensDuringLoad === ['attachment-preview'] && $data->loadingTexts === [true], 'Loading text should appear on the preview screen before downloading.');
  $preview->handleEvent(previewKey(SDL::KEY_BACKSPACE));
  previewCheck($preview->activeLeaf()?->instance() instanceof Table && $table->cursorRow() === 0, 'Backspace at the first attachment should stay put.');
  $preview->handleEvent(previewKeyUp(SDL::KEY_SPACE));
  previewCheck($preview->activeLeaf()?->instance() instanceof Text && $table->cursorRow() === 1, 'Space should open the next attachment.');
  $preview->handleEvent(previewKey(SDL::KEY_BACKSPACE));
  previewCheck($preview->activeLeaf()?->instance() instanceof Table && $table->cursorRow() === 0, 'Backspace should open the previous attachment.');
  previewCheck(count(array_filter($data->screensDuringLoad, static fn($id) => $id !== 'attachment-preview')) === 0, 'Preview navigation should not return to Ticket while loading.');
  $preview->handleEvent(previewKey(SDL::KEY_ESCAPE));
  previewCheck($window->currentScreenId() === 'ticket' && $table->cursorRow() === 0, 'Escape should restore the ticket and row.');
  $table->setCursor(3);
  Controller::openAttachmentPreview(new EventContext('accept', $table));
  previewCheck($window->currentScreenId() === 'ticket' && $data->downloads === 3, 'Unsupported files should stay on Ticket without downloading.');
  $ticket->statusBar->clear();
  $table->setCursor(1);
  Controller::openAttachmentPreview(new EventContext('accept', $table));
  previewCheck($preview->activeLeaf()?->instance() instanceof Text, 'SQL should open read-only text.');
  $preview->handleEvent(previewKeyUp(SDL::KEY_SPACE));
  previewCheck($preview->activeLeaf()?->instance() instanceof Image && $table->cursorRow() === 2, 'Space should open an image attachment.');
  $preview->handleEvent(previewKey(SDL::KEY_SPACE));
  previewCheck($preview->activeLeaf()?->instance() instanceof Image, 'Image space keydown should keep the current preview until keyup.');
  $preview->handleEvent(previewKeyUp(SDL::KEY_SPACE));
  previewCheck($window->currentScreenId() === 'ticket' && $table->cursorRow() === 3, 'An unsupported next attachment should show its ticket error.');
  $ticket->statusBar->clear();
  $table->setCursor(2);
  Controller::openAttachmentPreview(new EventContext('accept', $table));
  $preview->handleEvent(previewKey(SDL::KEY_RETURN));
  previewCheck($window->currentScreenId() === 'ticket' && $table->cursorRow() === 2, 'Return should restore the ticket and row.');
  $image = AttachmentPreview::widget('image', APP_DIR . '/Assets/logo.png', 'logo.png');
  previewCheck($image instanceof Image && $image->canActivate(), 'Image preview should allow SPTK zoom controls.');
  $beforeZoom = $image->destination(new Tile(0, 0, 100, 100));
  previewCheck($image->handleInput(previewKey(SDL::KEY_PLUS)), 'The SPTK image widget should accept zoom input.');
  $afterZoom = $image->destination(new Tile(0, 0, 100, 100));
  previewCheck($afterZoom->width > $beforeZoom->width, 'Zoom should enlarge the image.');
  echo "Attachment preview checks passed.\n";
} finally {
  $window->close();
}
