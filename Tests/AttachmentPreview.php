<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{AttachmentPreview, Controller, JiraData, Settings};
use MAJIRA\Jira\Client;
use SPTK\App;
use SPTK\Core\{Browser, Style, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\Tile;
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\{Image\Image, Table\Table, Text\Text};
use SPTK\XmlParser\{ScreenParser, ScreenSelector};

/** Record attachment deletes for the fake data source. */
final class AttachmentClient extends Client {

  public function __construct(private JiraData $data) {
    parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
  }

  public function addAttachment(string $issueKey, string $path): array {
    $this->data->uploaded[] = $path;
    return [];
  }

  public function deleteAttachment(string $attachmentId): array {
    $this->data->deleted[] = $attachmentId;
    return [];
  }

}

function previewCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Report whether a widget is shown on the screen, not only defined in it. */
function previewShown(SPTK\Core\Screen $screen, string $id): bool {
  foreach ($screen->layout->leaves() as $leaf) {
    if ($leaf->instance()->id() === $id) {
      return true;
    }
  }
  return false;
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
  public array $deleted = [];
  public array $uploaded = [];
  public array $remaining = [];
  public function __construct(private Window $window, private string $csv, private string $text, private string $image) {}
  public function client(): Client {
    return new AttachmentClient($this);
  }
  public function hasCachedTicket(string $key): bool {
    return false;
  }
  public function ticket(string $key, bool $refresh = false): array {
    return ['key' => $key, 'fields' => ['attachment' => $this->remaining]];
  }
  public function attachmentPath(array $attachment): string {
    if ($attachment['filename'] === 'gone.txt') {
      throw new RuntimeException('Jira download failed with HTTP 404.', 404);
    }
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
    ['id' => 'a1', 'filename' => 'sample.csv', 'content' => 'https://example.test/1'],
    ['id' => 'a2', 'filename' => 'sample.sql', 'content' => 'https://example.test/2'],
    ['id' => 'a3', 'filename' => 'logo.png', 'content' => 'https://example.test/3'],
    ['id' => 'a4', 'filename' => 'sheet.xls', 'content' => 'https://example.test/4'],
  ]]];
  (new ReflectionProperty(Controller::class, 'ticket'))->setValue(null, $ticketData);
  (new ReflectionMethod(Controller::class, 'renderTicket'))->invoke(null);
  $ticket->openDeckTile('ticket-attachments-card');
  $window->refreshLayout();
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
  (new ReflectionProperty(Controller::class, 'ticketKey'))->setValue(null, 'AP-1');
  $data->remaining = $ticketData['fields']['attachment'];
  unset($data->remaining[1]);
  $data->remaining = array_values($data->remaining);
  $table->setCursor(1);
  previewCheck(Controller::deleteSelectedAttachment(new EventContext('keyDown', $table)) && $ticket->statusBar->confirming(), 'Delete should ask before removing an attachment.');
  $ticket->statusBar->handleBlockingInput(previewKey(ord('n')));
  previewCheck($data->deleted === [] && $table->rowCount() === 4, 'Answering no should keep the attachment.');
  Controller::deleteSelectedAttachment(new EventContext('keyDown', $table));
  $ticket->statusBar->handleBlockingInput(previewKey(ord('y')));
  previewCheck($data->deleted === ['a2'] && $table->rowCount() === 3, 'Answering yes should delete the selected attachment and reload the list.');
  $uploadDir = sys_get_temp_dir() . '/majira-upload-' . bin2hex(random_bytes(4));
  mkdir($uploadDir . '/sub', 0700, true);
  file_put_contents($uploadDir . '/sub/notes.txt', 'x');
  $openSelector = static function() use ($ticket, $table, $uploadDir): mixed {
    foreach ($ticket->layout->leaves() as $leaf) {
      if ($leaf->instance() === $table) {
        $ticket->selectLeaf($leaf);
        break;
      }
    }
    $ticket->handleEvent(previewKey(SDL::KEY_INSERT));
    $selector = $ticket->widget('ticket-attachment-file');
    $selector?->setPath($uploadDir);
    return $selector;
  };
  $selector = $openSelector();
  previewCheck(previewShown($ticket, 'ticket-attachment-file') && !previewShown($ticket, 'ticket-attachments') && $ticket->activeLeaf()?->instance() === $selector, 'Insert should replace the attachment table with an active file selector.');
  $ticket->handleEvent(previewKey(SDL::KEY_ESCAPE));
  previewCheck($data->uploaded === [] && previewShown($ticket, 'ticket-attachments') && $ticket->selectedLeaf()?->instance() === $table, 'Escape should restore and select the table without uploading.');
  $selector = $openSelector();
  $selector->setValue($uploadDir . '/sub');
  $ticket->handleEvent(previewKey(SDL::KEY_RETURN));
  previewCheck($selector->path() === $uploadDir . '/sub' && previewShown($ticket, 'ticket-attachment-file'), 'Return on a directory should open it.');
  $selector->setValue($uploadDir . '/sub/notes.txt');
  $ticket->handleEvent(previewKey(SDL::KEY_RETURN));
  previewCheck($data->uploaded === [$uploadDir . '/sub/notes.txt'] && previewShown($ticket, 'ticket-attachments'), 'Return on a file should upload it and restore the table.');
  unlink($uploadDir . '/sub/notes.txt');
  rmdir($uploadDir . '/sub');
  rmdir($uploadDir);
  $ticket->statusBar->clear();
  $stale = ['key' => 'AP-1', 'fields' => ['attachment' => [['id' => 'a9', 'filename' => 'gone.txt', 'content' => 'https://example.test/9']]]];
  (new ReflectionProperty(Controller::class, 'ticket'))->setValue(null, $stale);
  (new ReflectionMethod(Controller::class, 'renderTicket'))->invoke(null);
  $data->remaining = [];
  $table->setCursor(0);
  Controller::openAttachmentPreview(new EventContext('accept', $table));
  previewCheck($window->currentScreenId() === 'ticket' && $table->rowCount() === 0, 'A deleted attachment should reload the ticket.');
  previewCheck($ticket->statusBar->kind() === 'error' && str_contains($ticket->statusBar->text(), 'no longer exists'), 'A deleted attachment should explain the reload.');
  $ticket->statusBar->clear();
  $paragraph = static fn(string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
  $linked = $ticketData;
  $linked['key'] = 'AP-1';
  $linked['fields']['description'] = ['type' => 'doc', 'content' => [$paragraph('Intro'), $paragraph('<@sample.sql>')]];
  $linked['fields']['comment'] = ['comments' => [['id' => 'c1', 'author' => ['displayName' => 'Ada'], 'created' => '2026-10-15T10:00:00.000+0200', 'body' => ['type' => 'doc', 'content' => [$paragraph('<@logo.png>')]]]]];
  (new ReflectionProperty(Controller::class, 'ticket'))->setValue(null, $linked);
  (new ReflectionMethod(Controller::class, 'renderTicket'))->invoke(null);
  $activate = static function(mixed $widget) use ($ticket): void {
    foreach ($ticket->layout->leaves() as $leaf) {
      if ($leaf->instance() === $widget) {
        $ticket->activateLeaf($leaf);
        return;
      }
    }
  };
  $ticket->openDeckTile('ticket-comments-card');
  $window->refreshLayout();
  $viewer = $ticket->widget('ticket-comments');
  $activate($viewer);
  $ticket->handleEvent(previewKey(ord('l')));
  previewCheck($window->currentScreenId() === 'ticket' && $ticket->statusBar->kind() === 'error', 'L on a comment line without a token should explain itself.');
  $ticket->statusBar->clear();
  $ticket->handleEvent(previewKey(SDL::KEY_DOWN));
  $ticket->handleEvent(previewKey(SDL::KEY_DOWN));
  $ticket->handleEvent(previewKey(ord('l')));
  previewCheck($window->currentScreenId() === 'attachment-preview' && $preview->activeLeaf()?->instance() instanceof Image, 'L on a comment token line should preview that attachment.');
  $preview->handleEvent(previewKey(SDL::KEY_ESCAPE));
  $ticket->openDeckTile('ticket-description-card');
  $window->refreshLayout();
  $description = $ticket->widget('ticket-description');
  $activate($description);
  $ticket->handleEvent(previewKey(SDL::KEY_DOWN));
  $ticket->handleEvent(previewKey(SDL::KEY_DOWN));
  $ticket->handleEvent(previewKey(ord('l')));
  previewCheck($window->currentScreenId() === 'ticket', 'Plain L in the description should stay text input.');
  $ticket->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => ord('l'), 'mod' => SDL::MOD_CTRL, 'repeat' => false]]);
  previewCheck($window->currentScreenId() === 'attachment-preview' && $preview->activeLeaf()?->instance() instanceof Text, 'Ctrl+L on a description token line should preview that attachment.');
  $preview->handleEvent(previewKey(SDL::KEY_ESCAPE));
  Settings::save(['site' => 'https://example.atlassian.net']);
  $opened = [];
  Browser::setOpener(static function(string $url) use (&$opened): void {
    $opened[] = $url;
  });
  $openLineLink = new ReflectionMethod(Controller::class, 'openLineLink');
  $ticketKey = new ReflectionProperty(Controller::class, 'ticketKey');
  $line = 'Ünnep [docs](https://example.test/docs) <https://example.atlassian.net/browse/AP-8?focusedCommentId=1> <#AP-7> https://example.test/bare.';
  $openLineLink->invoke(null, $line, 0);
  previewCheck($opened === ['https://example.test/docs'], 'A line link should open the first URL when the cursor is before every link.');
  $openLineLink->invoke(null, $line, mb_strpos($line, 'bare'));
  previewCheck($opened[1] === 'https://example.test/bare', 'A bare URL under the cursor should open without its trailing dot.');
  $openLineLink->invoke(null, $line, mb_strpos($line, '<#AP-7>') + 2);
  previewCheck($ticketKey->getValue() === 'AP-7' && count($opened) === 2, 'A ticket token under the cursor should open in majira.');
  $openLineLink->invoke(null, $line, mb_strpos($line, 'browse'));
  previewCheck($ticketKey->getValue() === 'AP-8' && count($opened) === 2, 'A Jira browse URL should open its ticket in majira.');
  $ticket->statusBar->clear();
  $openLineLink->invoke(null, 'plain text', 0);
  previewCheck($ticket->statusBar->kind() === 'error', 'A line without a link should explain itself.');
  Browser::setOpener(null);
  echo "Attachment preview checks passed.\n";
} finally {
  $window->close();
}
