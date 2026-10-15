<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{Controller, JiraData};
use MAJIRA\Jira\{Adf, Client};
use SPTK\App;
use SPTK\Core\{Style, Window};
use SPTK\Events\EventContext;
use SPTK\Rendering\Font;
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\Text\Text;
use SPTK\Widgets\TextEditor\TextEditor;
use SPTK\XmlParser\ScreenParser;

/** Record Jira comment mutations without making HTTP requests. */
final class CommentClient extends Client {

  public array $writes = [];

  public function __construct(public array $issue) {
    parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
  }

  public function myself(): array {
    return ['accountId' => 'ada'];
  }

  public function addComment(string $issueKey, array $body): array {
    $this->writes[] = 'add';
    $this->issue['fields']['comment']['comments'][] = [
      'id' => '3', 'author' => ['displayName' => 'Ada', 'accountId' => 'ada'],
      'created' => '2026-10-10T14:00:00+00:00', 'body' => $body,
    ];
    return [];
  }

  public function updateComment(string $issueKey, string $commentId, array $body): array {
    $this->writes[] = 'update:' . $commentId;
    foreach ($this->issue['fields']['comment']['comments'] as &$comment) {
      if ($comment['id'] === $commentId) {
        $comment['body'] = $body;
      }
    }
    return [];
  }

  public function deleteComment(string $issueKey, string $commentId): array {
    $this->writes[] = 'delete:' . $commentId;
    $this->issue['fields']['comment']['comments'] = array_values(array_filter(
      $this->issue['fields']['comment']['comments'],
      static fn(array $comment): bool => $comment['id'] !== $commentId,
    ));
    return [];
  }

}

/** Serve the fake client's latest ticket after a mutation. */
final class CommentData extends JiraData {

  public function __construct(private CommentClient $fake) {
  }

  public function client(): Client {
    return $this->fake;
  }

  public function ticket(string $key, bool $refresh = false): array {
    return $this->fake->issue;
  }

}

/** Fail a comment interaction check with a useful message. */
function commentCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Build one SDL keydown event. */
function commentKey(int $key, int $mod = 0): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => $key, 'mod' => $mod, 'repeat' => false]];
}

$issue = ['key' => 'AP-1', 'fields' => ['summary' => 'Comment ticket', 'comment' => ['comments' => [
  ['id' => '1', 'author' => ['displayName' => 'Ada', 'accountId' => 'ada'], 'created' => '2026-10-10T12:00:00+00:00', 'body' => Adf::fromMarkdown('First body')],
  ['id' => '2', 'author' => ['displayName' => 'Bob', 'accountId' => 'bob'], 'created' => '2026-10-10T13:00:00+00:00', 'body' => Adf::fromMarkdown('Other body')],
]]]];
$client = new CommentClient($issue);
putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
commentCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(App::class, 'font'))->setValue($app, $font);
$screen = (new ScreenParser())->parse('ticket.xml', new Style(), 'ticket', 'Ticket');
$window = new Window(['title' => 'Comment test', 'width' => 80, 'height' => 24, 'state' => 'hidden', 'resizable' => false, 'screens' => [$screen]]);
$screen->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, new CommentData($client));
  (new ReflectionProperty(Controller::class, 'ticket'))->setValue(null, $issue);
  (new ReflectionProperty(Controller::class, 'ticketKey'))->setValue(null, 'AP-1');
  (new ReflectionMethod(Controller::class, 'prepareTicketTiles'))->invoke(null);
  (new ReflectionMethod(Controller::class, 'renderTicket'))->invoke(null);
  $cardSummaries = (new ReflectionProperty(Controller::class, 'ticketCardSummaries'))->getValue();
  commentCheck(str_starts_with($cardSummaries['comments']->value(), '2 comments by '), 'A closed Comments card should summarize its comments.');
  Controller::expandTicketCard(new EventContext('select', $cardSummaries['comments']));
  $viewer = $screen->widget('ticket-comments');
  commentCheck($viewer instanceof Text && str_contains($viewer->text(), 'First body'), 'Comments should begin in a read-only Text widget.');
  $originalComments = $viewer->text();
  foreach ($screen->layout->leaves() as $leaf) {
    if ($leaf->instance() === $viewer) {
      $screen->selectLeaf($leaf);
      break;
    }
  }
  $screen->handleEvent(commentKey(SDL::KEY_RETURN));
  commentCheck($screen->activeLeaf()?->instance() === $viewer, 'The first Return should activate the read-only comment cursor.');
  $screen->handleEvent(commentKey(SDL::KEY_SPACE));
  commentCheck($screen->widget('ticket-comment-editor') === null && $viewer->text() === $originalComments, 'Space should leave the read-only viewer unchanged.');
  $screen->handleEvent(commentKey(SDL::KEY_RETURN));
  $editor = $screen->widget('ticket-comment-editor');
  commentCheck($editor instanceof TextEditor && $editor->getValue() === 'First body', 'Space should edit only the selected comment body.');
  $editor->setValue('Cancelled draft');
  $screen->handleEvent(commentKey(SDL::KEY_ESCAPE));
  commentCheck($screen->widget('ticket-comments') === $viewer && $client->writes === [], 'Escape should discard the comment draft.');
  $screen->handleEvent(commentKey(SDL::KEY_INSERT));
  $editor = $screen->widget('ticket-comment-editor');
  commentCheck($editor instanceof TextEditor && $editor->getValue() === '', 'Insert should open a blank comment.');
  $editor->setValue('New body');
  $screen->handleEvent(commentKey(SDL::KEY_RETURN, SDL::MOD_CTRL));
  commentCheck($client->writes === ['add'] && str_contains($viewer->text(), 'New body'), 'Ctrl+Return should add and refresh the comment.');
  $screen->handleEvent(commentKey(SDL::KEY_RETURN));
  $editor = $screen->widget('ticket-comment-editor');
  $editor->setValue('Changed body');
  $screen->handleEvent(commentKey(SDL::KEY_RETURN, SDL::MOD_CTRL));
  commentCheck($client->writes === ['add', 'update:1'] && str_contains($viewer->text(), 'Changed body'), 'Ctrl+Return should update the selected comment.');
  $screen->handleEvent(commentKey(SDL::KEY_DELETE));
  commentCheck($screen->statusBar->confirming(), 'Delete should require confirmation.');
  $screen->handleEvent(commentKey(SDL::KEY_ESCAPE));
  commentCheck($client->writes === ['add', 'update:1'], 'Escape should decline deletion.');
  $screen->handleEvent(commentKey(SDL::KEY_DELETE));
  $screen->handleEvent(commentKey(SDL::KEY_RETURN));
  commentCheck($client->writes === ['add', 'update:1', 'delete:1'] && !str_contains($viewer->text(), 'Changed body'), 'Confirming should delete and refresh the comment.');
  $screen->handleEvent(commentKey(SDL::KEY_RETURN));
  commentCheck($screen->statusBar->kind() === 'error' && $screen->widget('ticket-comment-editor') === null, 'Another author’s comment should not open for editing.');
  $screen->handleEvent(commentKey(SDL::KEY_ESCAPE));
  $screen->handleEvent(commentKey(SDL::KEY_DELETE));
  commentCheck($screen->statusBar->kind() === 'error' && !$screen->statusBar->confirming(), 'Another author’s comment should not be offered for deletion.');
  $screen->handleEvent(commentKey(SDL::KEY_ESCAPE));
  foreach ($screen->layout->leaves() as $leaf) {
    if ($leaf->instance() === $viewer) {
      $screen->activateLeaf($leaf);
      break;
    }
  }
  for ($row = 0; $row < 5; $row++) {
    $screen->handleEvent(commentKey(SDL::KEY_DOWN));
  }
  $screen->handleEvent(commentKey(SDL::KEY_RETURN));
  commentCheck($screen->widget('ticket-comment-editor')?->getValue() === 'New body', 'The cursor should select the later comment instead of the first one.');
  $screen->handleEvent(commentKey(SDL::KEY_ESCAPE));
  echo "Comment checks passed.\n";
} finally {
  $window->close();
}
