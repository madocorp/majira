<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\BoardState;
use MAJIRA\App\BoardColumnFill;
use MAJIRA\App\BoardTicketCard;
use MAJIRA\App\BoardView;
use MAJIRA\App\BoardCache;
use MAJIRA\App\BoardResultCache;
use MAJIRA\App\Controller;
use MAJIRA\App\JiraData;
use MAJIRA\App\ProjectCache;
use MAJIRA\App\Settings;
use MAJIRA\App\SprintCache;
use MAJIRA\Jira\Client;
use SPTK\Core\Style;
use SPTK\Core\Window;
use SPTK\Events\EventContext;
use SPTK\Layout\{LayoutNode, LayoutSeparator, Tile};
use SPTK\Rendering\{Font, Grid, GridWriter};
use SPTK\SDLWrapper\{SDL, TTF};
use SPTK\Widgets\List\ListView;
use SPTK\Widgets\Button\Button;
use SPTK\XmlParser\ScreenParser;

function boardCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Read one painted row without depending on the SDL renderer. */
function boardRow(Grid $grid, int $x, int $y, int $width): string {
  $text = '';
  for ($column = $x; $column < $x + $width; $column++) {
    $text .= $grid->cell($column, $y)->glyph;
  }
  return $text;
}

/** Read one retained-frame pixel to compare tile content with its padding. */
function boardPixel(SDL $sdl, Window $window, int $x, int $y): int {
  $renderer = (new ReflectionProperty(Window::class, 'ffiRenderer'))->getValue($window);
  $frame = (new ReflectionProperty(Window::class, 'frameTexture'))->getValue($window);
  $previous = $sdl->ffi->SDL_GetRenderTarget($renderer);
  $sdl->ffi->SDL_SetRenderTarget($renderer, $frame);
  $surface = $sdl->ffi->SDL_RenderReadPixels($renderer, null);
  $rgba = $sdl->ffi->SDL_ConvertSurface($surface, SDL::SDL_PIXELFORMAT_RGBA8888);
  $pixel = FFI::cast('uint32_t*', $rgba->pixels)[$y * intdiv($rgba->pitch, 4) + $x];
  $sdl->ffi->SDL_DestroySurface($rgba);
  $sdl->ffi->SDL_DestroySurface($surface);
  $sdl->ffi->SDL_SetRenderTarget($renderer, $previous);
  return $pixel;
}

$testHome = sys_get_temp_dir() . '/majira-board-test-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
Settings::save(['projectKey' => 'LIST', 'boardId' => '5', 'sprintId' => '50']);
BoardState::save(['projectKey' => 'BOARD', 'boardId' => '7', 'sprintId' => '70']);
boardCheck(BoardState::load()['sprintId'] === '70' && Settings::load()['sprintId'] === '50', 'Board selectors must stay separate from List filters.');

$client = new class extends Client {
  public array $starts = [];
  public function __construct() { parent::__construct('https://example.atlassian.net', 'test@example.com', 'token'); }
  public function boardConfiguration(int $boardId): array {
    boardCheck($boardId === 7, 'Wrong board configuration requested.');
    return ['columnConfig' => ['columns' => [
      ['name' => 'To Do', 'statuses' => [['id' => '1'], ['id' => '2']]],
      ['name' => 'Done', 'statuses' => [['id' => '3']]],
    ]]];
  }
  public function boardSprintIssues(int $boardId, int $sprintId, string $jql, array $fields, int $maxResults = 100, int $startAt = 0): array {
    boardCheck($boardId === 7 && $sprintId === 70 && $jql === '', 'Wrong sprint issue scope.');
    boardCheck(in_array('issuetype', $fields, true), 'Board requests must include issue types.');
    $this->starts[] = $startAt;
    return $startAt === 0
      ? ['issues' => [['key' => 'BOARD-1', 'fields' => ['summary' => 'First', 'status' => ['id' => '2', 'name' => 'Ready'], 'issuetype' => ['name' => 'Task']]]], 'total' => 2]
      : ['issues' => [['key' => 'BOARD-2', 'fields' => ['summary' => 'Second', 'status' => ['id' => '3', 'name' => 'Done'], 'issuetype' => ['name' => 'Bug']]]], 'total' => 2];
  }
};
$data = new class($client) extends JiraData {
  public function __construct(private Client $fake) {}
  public function client(): Client { return $this->fake; }
};
$result = $data->boardSprint('7', '70');
boardCheck($client->starts === [0, 1], 'Board sprint issues must load every page.');
boardCheck(BoardResultCache::has('7', '70') && $data->boardSprint('7', '70') === $result && $client->starts === [0, 1], 'A selected sprint reuses its complete cached result.');
boardCheck($data->boardSprint('7', '70', true) === $result && $client->starts === [0, 1, 0, 1], 'Refreshing a board fetches all pages again.');
BoardResultCache::save('7', '71', ['configuration' => [], 'issues' => []]);
boardCheck($data->boardSprint('7', '71') === ['configuration' => [], 'issues' => []] && $client->starts === [0, 1, 0, 1], 'An empty Board result is cached by sprint.');
boardCheck(!BoardResultCache::has('8', '70'), 'Board results are scoped by board.');
$columns = BoardView::columns($result['configuration'], $result['issues']);
boardCheck(array_column($columns, 'name') === ['To Do', 'Done'], 'Board column order must follow Jira configuration.');
boardCheck($columns[0]['issues'][0]['key'] === 'BOARD-1' && $columns[1]['issues'][0]['key'] === 'BOARD-2', 'Issues must use their mapped status columns.');
boardCheck(array_column(BoardView::columns($result['configuration'], [$result['issues'][0]]), 'name') === ['To Do'], 'Board grouping omits configured columns without issues.');
boardCheck(BoardView::columns($result['configuration'], []) === [], 'An empty sprint has no visible columns.');
$sampleCard = new BoardTicketCard('AP-1234', 'Wrapped title', 'Jane Smith');
$cardGrid = new Grid(12, 4);
$sampleCard->paint(new GridWriter($cardGrid, new Tile(0, 0, 12, 4)));
boardCheck(str_contains(boardRow($cardGrid, 0, 0, 12), '#AP-1234') && str_contains(boardRow($cardGrid, 0, 1, 12), 'Wrapped') && str_contains(boardRow($cardGrid, 0, 2, 12), 'title') && str_contains(boardRow($cardGrid, 0, 3, 12), 'Jane Smith'), 'A ticket card shows its key, wrapped summary, and full assignee name.');
boardCheck((array)$cardGrid->cell(0, 0)->bg === (array)(new Style())->background, 'Ticket cards use the default tile background.');
$typedCard = new BoardTicketCard('AP-1234', 'Summary', 'Jane Smith', 'Sub-task');
$typedGrid = new Grid(20, 4);
$typedCard->paint(new GridWriter($typedGrid, new Tile(0, 0, 20, 4)));
boardCheck(str_contains(boardRow($typedGrid, 0, 0, 20), '#AP-1234') && str_ends_with(rtrim(boardRow($typedGrid, 0, 0, 20)), 'Sub-task'), 'Issue type is right aligned beside the key.');
$narrowGrid = new Grid(16, 4);
$typedCard->paint(new GridWriter($narrowGrid, new Tile(0, 0, 16, 4)));
boardCheck(str_contains(boardRow($narrowGrid, 0, 0, 16), '#AP-1234') && str_ends_with(rtrim(boardRow($narrowGrid, 0, 0, 16)), 'Sub-…'), 'Long issue types are shortened without covering the key.');

$screen = (new ScreenParser())->parse('board.xml', new Style(), 'board', 'Board');
$listScreen = (new ScreenParser())->parse('list.xml', new Style(), 'list', 'List');
$screen->measureGrid(new Tile(0, 0, 132, 54));
boardCheck($screen->layout->findNode('board-picker-slot')->grid()->height === 0, 'The closed picker must take no board rows.');
boardCheck($screen->widget('board-help') !== null && $screen->layout->findNode('board-body')->grid()->y === 2, 'The closed picker must not leave a spare row before the board.');
foreach (['project', 'board', 'sprint'] as $kind) {
  boardCheck($screen->widget('board-' . $kind . '-button') !== null, 'Missing Board selector: ' . $kind);
}
$selectorRow = (new ReflectionProperty(LayoutNode::class, 'children'))->getValue($screen->layout)[0];
$selectorChildren = (new ReflectionProperty(LayoutNode::class, 'children'))->getValue($selectorRow);
boardCheck(count(array_filter($selectorChildren, fn($child) => $child instanceof LayoutSeparator)) === 2, 'Board selectors need separators between them.');
boardCheck(count(array_filter($selectorChildren, fn($child) => $child instanceof \SPTK\Layout\LayoutLeaf && $child->instance() instanceof Button)) === 3, 'Board selector row has no Refresh button.');
boardCheck(!in_array($screen->statusBar, array_map(fn($leaf) => $leaf->instance(), $screen->layout->movementLeaves()), true), 'Status bar must not take ordinary arrow focus.');

ProjectCache::save([['key' => 'BOARD', 'name' => 'Board project']]);
BoardCache::save('BOARD', [['id' => '7', 'name' => 'Team board']]);
SprintCache::save('7', [['id' => '70', 'name' => 'Sprint', 'state' => 'active']]);
putenv('SDL_VIDEODRIVER=dummy');
$sdl = new SDL();
$sdl->checkReturnValue($sdl->ffi->SDL_Init(SDL::SDL_INIT_VIDEO), 'SDL_Init');
$ttf = new TTF();
boardCheck((bool)$ttf->ffi->TTF_Init(), 'TTF_Init failed.');
$font = new Font($ttf);
$font->open('LiberationMono-Bold', 17);
$app = (new ReflectionClass(SPTK\App::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\App::class, 'instance'))->setValue(null, $app);
(new ReflectionProperty(SPTK\App::class, 'sdl'))->setValue($app, $sdl);
(new ReflectionProperty(SPTK\App::class, 'font'))->setValue($app, $font);
$window = new Window([
  'title' => 'Board focus test', 'width' => 80, 'height' => 20,
  'state' => 'hidden', 'resizable' => false, 'screens' => [$screen, $listScreen],
]);
$screen->statusBar->setScheduler(static function(int $delayMs, callable $callback): void {});
try {
  (new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
  (new ReflectionMethod(Controller::class, 'prepareBoard'))->invoke(null);
  $escape = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_ESCAPE, 'mod' => 0, 'repeat' => false]];
  $enter = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RETURN, 'mod' => 0, 'repeat' => false]];
  $right = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RIGHT, 'mod' => 0, 'repeat' => false]];
  $previous = $screen->selectedLeaf();
  (new ReflectionMethod(Controller::class, 'status'))->invoke(null, 'Saved filter.');
  boardCheck($screen->statusBar->kind() === 'notice' && $screen->selectedLeaf()->instance() === $screen->statusBar, 'Action result must take status focus.');
  $screen->handleEvent($right);
  boardCheck($screen->selectedLeaf()->instance() === $screen->statusBar, 'Action result must drain navigation.');
  $screen->handleEvent($enter);
  boardCheck($screen->selectedLeaf() === $previous && $screen->statusBar->kind() === 'empty', 'Return acknowledges action result and restores focus.');
  (new ReflectionProperty(Controller::class, 'issues'))->setValue(null, [['key' => 'BOARD-1']]);
  (new ReflectionProperty(Controller::class, 'meta'))->setValue(null, ['hasMore' => true]);
  (new ReflectionMethod(Controller::class, 'reportTicketCount'))->invoke(null);
  boardCheck($screen->statusBar->kind() === 'empty', 'More-ticket guidance must not appear on the Board screen.');
  boardCheck(str_contains($listScreen->statusBar->text(), 'more available') && $listScreen->statusBar->behavior() === 'continuous', 'More-ticket guidance belongs to the List screen.');
  $window->setCurrentScreenId('list');
  boardCheck($window->currentScreenId() === 'list' && str_contains($listScreen->statusBar->text(), 'more available'), 'List shows its pagination guidance when opened.');
  $window->setCurrentScreenId('board');
  (new ReflectionMethod(Controller::class, 'continuousStatus'))->invoke(null, 'Showing more tickets.');
  boardCheck($screen->selectedLeaf() === $previous && $screen->statusBar->kind() === 'notice' && $screen->statusBar->behavior() === 'continuous', 'Ongoing ticket guidance stays passive.');
  foreach (['project' => 'openBoardProjects', 'board' => 'openBoardBoards', 'sprint' => 'openBoardSprints'] as $kind => $action) {
    Controller::$action(new EventContext('activate'));
    boardCheck($screen->activeLeaf()?->instance() instanceof ListView, 'Board ' . $kind . ' picker must activate its list.');
    $screen->handleEvent($escape);
    boardCheck($screen->selectedLeaf()?->instance() === $screen->widget('board-' . $kind . '-button'), 'Closing ' . $kind . ' picker must restore its button focus.');
  }
  Controller::openBoardSprints(new EventContext('activate'));
  $screen->handleEvent($enter);
  boardCheck($screen->selectedLeaf()?->instance() === $screen->widget('board-sprint-button'), 'Choosing a sprint must restore sprint button focus.');
  $loadedCard = (new ReflectionProperty(Controller::class, 'boardColumnLeaves'))->getValue()[0][0]->instance();
  $loadedGrid = new Grid(24, 4);
  $loadedCard->paint(new GridWriter($loadedGrid, new Tile(0, 0, 24, 4)));
  boardCheck(str_ends_with(rtrim(boardRow($loadedGrid, 0, 0, 24)), 'Task'), 'A loaded Board issue passes its Jira type to the card.');

  $wideColumns = [];
  for ($index = 0; $index < 7; $index++) {
    $wideColumns[] = ['name' => 'Stage ' . ($index + 1), 'issues' => [
      ['key' => 'SCROLL-' . $index . '-A', 'fields' => ['summary' => 'First card']],
      ['key' => 'SCROLL-' . $index . '-B', 'fields' => ['summary' => 'Second card']],
    ]];
  }
  for ($index = 2; $index < 9; $index++) {
    $wideColumns[0]['issues'][] = ['key' => 'SCROLL-0-' . $index, 'fields' => ['summary' => 'Extra card ' . $index, 'assignee' => ['displayName' => 'Alex Person']]];
  }
  (new ReflectionMethod(Controller::class, 'renderBoardColumns'))->invoke(null, $wideColumns);
  $leaves = (new ReflectionProperty(Controller::class, 'boardColumnLeaves'))->getValue();
  $firstStack = (new ReflectionProperty(Controller::class, 'boardColumnStacks'))->getValue()[0];
  $firstStackChildren = (new ReflectionProperty(LayoutNode::class, 'children'))->getValue($firstStack);
  boardCheck(count(array_filter($firstStackChildren, fn($child) => $child instanceof LayoutSeparator)) === count($leaves[0]) - 1, 'Adjacent cards use SPTK layout separators.');
  boardCheck(count($leaves) === 7 && $screen->widget('board-overflow') === null, 'The board uses no separate column map row.');
  boardCheck($screen->statusBar->text() === '** Stage 1 ** | Stage 2 | Stage 3 | Stage 4 | Stage 5 | Stage 6 | Stage 7' && $screen->statusBar->behavior() === 'continuous', 'The status bar maps every column and marks the current one.');
  boardCheck($leaves[0][0]->grid()->y === 4, 'A column heading sits above its first card without an extra map row.');
  $visibleCards = array_values(array_filter($leaves, fn($cards) => in_array($cards[0], $screen->layout->movementLeaves(), true)));
  boardCheck(count($visibleCards) === 4, 'Only four ticket columns are navigable at once.');
  $widths = array_map(fn($cards) => $cards[0]->grid()->width, $visibleCards);
  boardCheck(max($widths) - min($widths) <= 1 && min($widths) > 0, 'Four visible columns share the board width evenly.');
  boardCheck($leaves[0][0]->instance() instanceof BoardTicketCard && $leaves[0][0]->grid()->height === BoardTicketCard::HEIGHT, 'Tickets are fixed-height tiles.');
  $screen->measureGrid(new Tile(0, 0, 132, 20));
  $painted = new Grid(132, 20);
  $screen->paint($painted);
  boardCheck(str_contains(boardRow($painted, $leaves[0][0]->grid()->x, 2, $leaves[0][0]->grid()->width), 'Stage 1 (9)') && str_contains(boardRow($painted, $leaves[0][0]->grid()->x, 2, $leaves[0][0]->grid()->width), '▼'), 'Column title shows its total and hidden cards below.');
  boardCheck((array)$painted->cell($leaves[0][0]->grid()->x, 2)->bg === (array)(new Style())->background->darkened(), 'Unselected column headings use the dimmed tile background.');
  $window->refreshLayout();
  $screen->selectLeaf($leaves[0][0]);
  $pageDown = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_PAGEDOWN, 'mod' => 0, 'repeat' => false]];
  $pageUp = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_PAGEUP, 'mod' => 0, 'repeat' => false]];
  $slots = max(1, intdiv($firstStack->grid()->height + 1, BoardTicketCard::HEIGHT + 1));
  boardCheck($slots >= 2 && 2 * $slots <= count($leaves[0]), 'Paging fixture needs at least two visible card pages.');
  $screen->handleEvent($pageDown);
  boardCheck($screen->selectedLeaf() === $leaves[0][$slots - 1] && $firstStack->scrollOffset() === 0, 'PgDown first selects the last visible card.');
  $screen->handleEvent($pageDown);
  boardCheck($screen->selectedLeaf() === $leaves[0][2 * $slots - 1] && $firstStack->scrollOffset() === $slots * (BoardTicketCard::HEIGHT + 1), 'PgDown again advances to the next page.');
  $screen->handleEvent($pageUp);
  boardCheck($screen->selectedLeaf() === $leaves[0][$slots] && $firstStack->scrollOffset() === $slots * (BoardTicketCard::HEIGHT + 1), 'PgUp first selects the first visible card.');
  $screen->handleEvent($pageUp);
  boardCheck($screen->selectedLeaf() === $leaves[0][0] && $firstStack->scrollOffset() === 0, 'PgUp again returns to the previous page.');
  $screen->selectLeaf($leaves[1][0]);
  $screen->handleEvent($pageDown);
  $screen->handleEvent($pageDown);
  boardCheck($screen->selectedLeaf() === $leaves[1][1] && $firstStack->scrollOffset() === 0, 'Paging stays within the current column and stops at its last card.');
  $screen->handleEvent($pageUp);
  $screen->handleEvent($pageUp);
  boardCheck($screen->selectedLeaf() === $leaves[1][0], 'PgUp stops at the first card of a short column.');
  $down = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_DOWN, 'mod' => 0, 'repeat' => false]];
  $screen->selectLeaf($leaves[0][7]);
  $screen->handleEvent($down);
  boardCheck($screen->selectedLeaf() === $leaves[0][8] && $leaves[0][8]->visibleGrid()->height === BoardTicketCard::HEIGHT, 'Down scrolls to the last full card in its column.');
  $painted = new Grid($screen->layout->grid()->width, $screen->layout->grid()->height);
  $screen->paint($painted);
  boardCheck(str_contains(boardRow($painted, $leaves[0][0]->grid()->x, 2, $leaves[0][0]->grid()->width), '▲'), 'Column title counts hidden cards above after scrolling.');
  $screen->selectLeaf($leaves[3][1]);
  $window->refreshLayout();
  $geometry = (new ReflectionProperty(Window::class, 'geometry'))->getValue($window);
  $selectedCard = $leaves[3][1]->grid();
  $otherCard = $leaves[2][1]->grid();
  $pixelY = ($selectedCard->y + 1) * $geometry->cellHeight + $geometry->offsetY + intdiv($geometry->cellHeight, 2);
  $selectedPadding = boardPixel($sdl, $window, ($selectedCard->x - 1) * $geometry->cellWidth + $geometry->offsetX + intdiv($geometry->cellWidth, 2), $pixelY);
  $selectedCell = boardPixel($sdl, $window, $selectedCard->x * $geometry->cellWidth + $geometry->offsetX + intdiv($geometry->cellWidth, 2), $pixelY);
  $otherPadding = boardPixel($sdl, $window, ($otherCard->x - 1) * $geometry->cellWidth + $geometry->offsetX + intdiv($geometry->cellWidth, 2), $pixelY);
  $otherCell = boardPixel($sdl, $window, $otherCard->x * $geometry->cellWidth + $geometry->offsetX + intdiv($geometry->cellWidth, 2), $pixelY);
  boardCheck($selectedPadding === $selectedCell && $otherPadding === $otherCell && $selectedCell !== $otherCell, 'Card padding matches the selected and unselected card backgrounds.');
  $firstOtherCard = $leaves[2][0]->grid();
  $lineY = ($firstOtherCard->y + $firstOtherCard->height) * $geometry->cellHeight + $geometry->offsetY + intdiv($geometry->cellHeight + 1, 2);
  $linePadding = boardPixel($sdl, $window, ($firstOtherCard->x - 1) * $geometry->cellWidth + $geometry->offsetX + intdiv($geometry->cellWidth, 2), $lineY);
  $lineCenter = boardPixel($sdl, $window, ($firstOtherCard->x + intdiv($firstOtherCard->width, 2)) * $geometry->cellWidth + $geometry->offsetX + intdiv($geometry->cellWidth, 2), $lineY);
  boardCheck($linePadding === $lineCenter && $lineCenter !== $otherCell, 'The separator between cards reaches the padded column edge.');
  $softBlue = (new Style())->background->darkened(0.6);
  $softBluePixel = ($softBlue->r << 24) | ($softBlue->g << 16) | ($softBlue->b << 8) | 0xff;
  boardCheck($lineCenter === $softBluePixel, 'Card separators use the column stack color.');
  $columnSeparators = array_values(array_filter((new ReflectionProperty(LayoutNode::class, 'children'))->getValue((new ReflectionProperty(Controller::class, 'boardBody'))->getValue()), fn($child) => $child instanceof LayoutSeparator));
  $columnLine = (new ReflectionProperty(LayoutSeparator::class, 'area'))->getValue($columnSeparators[1]);
  $gray = (new Style())->separator;
  $grayPixel = ($gray->r << 24) | ($gray->g << 16) | ($gray->b << 8) | 0xff;
  boardCheck(boardPixel($sdl, $window, $columnLine->x, $lineY) === $grayPixel && boardPixel($sdl, $window, $columnLine->x + 1, $lineY) === $grayPixel, 'The vertical column separator covers both pixels where it meets a card separator.');
  boardCheck(str_contains($screen->statusBar->text(), '** Stage 4 **'), 'The status map follows a board column selection notification.');
  $screen->handleEvent($right);
  boardCheck($screen->selectedLeaf() === $leaves[4][0] && str_contains($screen->statusBar->text(), '** Stage 5 **') && (new ReflectionProperty(Controller::class, 'boardViewportStart'))->getValue() === 1, 'Right at the viewport edge reveals and highlights the next column.');
  $screen->statusBar->info('Refreshing board.');
  boardCheck($screen->statusBar->text() === 'Refreshing board.', 'A temporary message covers the column map.');
  $screen->handleEvent($enter);
  boardCheck(str_contains($screen->statusBar->text(), '** Stage 5 **') && $screen->selectedLeaf() === $leaves[4][0], 'Acknowledging a temporary message restores the map and board focus.');
  boardCheck($leaves[0][8]->visibleGrid()->height === BoardTicketCard::HEIGHT, 'Horizontal scrolling preserves another column scroll position.');
  $screen->handleEvent($right);
  boardCheck($screen->selectedLeaf() === $leaves[5][0], 'Right moves to the next column tile.');
  $screen->selectLeaf($leaves[2][0]);
  $left = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_LEFT, 'mod' => 0, 'repeat' => false]];
  $screen->handleEvent($left);
  boardCheck($screen->selectedLeaf() === $leaves[1][0] && str_contains($screen->statusBar->text(), '** Stage 2 **') && (new ReflectionProperty(Controller::class, 'boardViewportStart'))->getValue() === 1, 'Left at the viewport edge reveals and highlights the preceding column.');
  $home = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_HOME, 'mod' => 0, 'repeat' => false]];
  $end = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_END, 'mod' => 0, 'repeat' => false]];
  $screen->selectLeaf($leaves[3][0]);
  $screen->handleEvent($home);
  boardCheck($screen->selectedLeaf() === $leaves[1][0] && (new ReflectionProperty(Controller::class, 'boardViewportStart'))->getValue() === 1, 'Home first selects the first visible column.');
  $screen->handleEvent($home);
  boardCheck($screen->selectedLeaf() === $leaves[0][8] && (new ReflectionProperty(Controller::class, 'boardViewportStart'))->getValue() === 0, 'Home again moves to the previous column page and restores its card.');
  $screen->handleEvent($home);
  boardCheck($screen->selectedLeaf() === $leaves[0][8], 'Home stops at the first column.');
  $screen->handleEvent($end);
  boardCheck($screen->selectedLeaf() === $leaves[3][0] && (new ReflectionProperty(Controller::class, 'boardViewportStart'))->getValue() === 0, 'End first selects the last visible column.');
  $screen->handleEvent($end);
  boardCheck($screen->selectedLeaf() === $leaves[6][0] && (new ReflectionProperty(Controller::class, 'boardViewportStart'))->getValue() === 3, 'End again moves to the next column page.');
  $screen->handleEvent($end);
  boardCheck($screen->selectedLeaf() === $leaves[6][0], 'End stops at the last column.');
  $screen->handleEvent($home);
  $screen->handleEvent($home);
  $screen->selectLeaf($leaves[1][0]);
  (new ReflectionMethod(Controller::class, 'renderBoardColumns'))->invoke(null, $wideColumns);
  $refreshedLeaves = (new ReflectionProperty(Controller::class, 'boardColumnLeaves'))->getValue();
  boardCheck($screen->selectedLeaf() === $refreshedLeaves[1][0] && $window->currentScreenId() === 'board', 'Refreshing a board column preserves its selected card.');
  $screen->handleEvent($left);
  boardCheck($screen->selectedLeaf() === $refreshedLeaves[0][8] && $refreshedLeaves[0][8]->visibleGrid()->height === BoardTicketCard::HEIGHT, 'Refreshing preserves another column card and scroll position.');
  (new ReflectionMethod(Controller::class, 'renderBoardColumns'))->invoke(null, [['name' => 'Empty', 'issues' => []]]);
  $emptyCard = (new ReflectionProperty(Controller::class, 'boardColumnLeaves'))->getValue()[0][0];
  boardCheck($emptyCard->instance() instanceof BoardTicketCard && !$emptyCard->instance()->canActivate(), 'An empty column keeps a non-opening placeholder tile.');
  $painted = new Grid($screen->layout->grid()->width, $screen->layout->grid()->height);
  $screen->paint($painted);
  boardCheck(str_contains(boardRow($painted, $emptyCard->grid()->x, 2, $emptyCard->grid()->width), 'Empty (0)'), 'An empty column displays zero tickets.');
  boardCheck((array)$painted->cell($emptyCard->grid()->x, $emptyCard->grid()->y + BoardTicketCard::HEIGHT + 1)->bg === (array)(new Style())->background->darkened(), 'Unused column rows match the dimmed tile background.');
  $emptyStack = (new ReflectionProperty(Controller::class, 'boardColumnStacks'))->getValue()[0]->grid();
  boardCheck((array)$painted->cell($emptyStack->x, $emptyStack->y + $emptyStack->height - 1)->bg === (array)(new Style())->background->darkened(), 'The dimmed fill reaches the bottom of a short column.');
  $fill = array_values(array_filter($screen->layout->leaves(), fn($leaf) => $leaf->instance() instanceof BoardColumnFill))[0]->instance();
  boardCheck($fill->dimBackgroundWhenUnselected() && $fill->dimContentWhenUnselected(), 'Unused column padding and cells both use ordinary tile dimming.');
  (new ReflectionProperty(Controller::class, 'data'))->setValue(null, $data);
  Controller::refreshBoard(new EventContext('activate'));
  boardCheck($client->starts === [0, 1, 0, 1, 0, 1] && BoardResultCache::load('7', '70') === $result, 'Board Refresh fetches every page and replaces its cached result.');
  $reload = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => ord('r'), 'mod' => 0, 'repeat' => false]];
  $screen->handleEvent($reload);
  boardCheck($client->starts === [0, 1, 0, 1, 0, 1, 0, 1], 'R reloads the selected sprint from Jira.');
  Controller::openBoardSprints(new EventContext('activate'));
  $screen->handleEvent($enter);
  boardCheck((new ReflectionProperty(Controller::class, 'boardIssueKeys'))->getValue() !== [] && $client->starts === [0, 1, 0, 1, 0, 1, 0, 1], 'Choosing the current sprint loads its cached issues.');
  Controller::openBoardBoards(new EventContext('activate'));
  $screen->handleEvent($enter);
  boardCheck((new ReflectionProperty(Controller::class, 'boardIssueKeys'))->getValue() === [] && BoardState::load()['sprintId'] === '70', 'Choosing the current board clears displayed issues.');
  Controller::openBoardProjects(new EventContext('activate'));
  $screen->handleEvent($enter);
  boardCheck((new ReflectionProperty(Controller::class, 'boardIssueKeys'))->getValue() === [] && BoardState::load()['boardId'] === '7', 'Choosing the current project clears displayed issues.');
  $filterIssues = [];
  for ($person = 1; $person <= 12; $person++) {
    $count = $person === 11 ? 3 : ($person === 10 ? 2 : 1);
    for ($ticket = 1; $ticket <= $count; $ticket++) {
      $filterIssues[] = ['key' => 'PERSON-' . $person . '-' . $ticket, 'fields' => [
        'summary' => 'Assigned ticket', 'status' => ['id' => '2'],
        'assignee' => ['accountId' => 'account-' . $person, 'displayName' => 'Person ' . $person],
      ]];
    }
  }
  (new ReflectionMethod(Controller::class, 'showBoardResult'))->invoke(null, ['configuration' => $result['configuration'], 'issues' => $filterIssues], false);
  boardCheck((new ReflectionProperty(Controller::class, 'boardColumnTitles'))->getValue() === ['To Do'], 'The Board hides columns without visible cards.');
  $people = (new ReflectionProperty(Controller::class, 'boardAssignees'))->getValue();
  boardCheck(count($people) === 12 && $people[0]['name'] === 'Person 11' && $people[0]['count'] === 3 && $people[1]['name'] === 'Person 10', 'Assignees are ordered by ticket count.');
  $f = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => ord('f'), 'mod' => 0, 'repeat' => false]];
  $screen->handleEvent($f);
  $assigneeRow = (new ReflectionProperty(Controller::class, 'boardAssigneeOpen'))->getValue();
  $buttons = (new ReflectionProperty(Controller::class, 'boardAssigneeButtons'))->getValue();
  boardCheck($assigneeRow->grid()->height === 1 && count($buttons) === 6 && $buttons['0']['key'] === '' && $buttons['1']['key'] === 'id:account-11', 'F opens one row with Everybody and the five busiest people.');
  $screen->handleEvent($f);
  $buttons = (new ReflectionProperty(Controller::class, 'boardAssigneeButtons'))->getValue();
  boardCheck(count($buttons) === 6 && $buttons['0']['key'] === '' && $buttons['1']['key'] === $people[5]['key'], 'F shows the next five people and keeps 0 for Everybody.');
  $screen->handleEvent($f);
  $buttons = (new ReflectionProperty(Controller::class, 'boardAssigneeButtons'))->getValue();
  boardCheck(count($buttons) === 3 && $buttons['1']['key'] === $people[10]['key'], 'F reaches the last, shorter page of people.');
  $screen->handleEvent($f);
  $buttons = (new ReflectionProperty(Controller::class, 'boardAssigneeButtons'))->getValue();
  boardCheck(count($buttons) === 6 && $buttons['1']['key'] === $people[0]['key'], 'F cycles back to the first page.');
  $screen->handleEvent($f);
  $oneDown = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => ord('1'), 'mod' => 0, 'repeat' => false]];
  $oneUp = (object)['type' => SDL::SDL_EVENT_KEY_UP, 'key' => (object)['key' => ord('1'), 'mod' => 0, 'repeat' => false]];
  $screen->handleEvent($oneDown);
  $screen->handleEvent($oneUp);
  boardCheck((new ReflectionProperty(Controller::class, 'boardAssigneeOpen'))->getValue() === null && array_keys((new ReflectionProperty(Controller::class, 'boardIssueKeys'))->getValue()) === ['PERSON-3-1'], 'A number hotkey applies its assignee and closes the selector.');
  boardCheck(str_contains($screen->statusBar->text(), 'Assignee: Person 3'), 'The board status identifies the active assignee.');
  $screen->handleEvent($f);
  $zeroDown = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => ord('0'), 'mod' => 0, 'repeat' => false]];
  $zeroUp = (object)['type' => SDL::SDL_EVENT_KEY_UP, 'key' => (object)['key' => ord('0'), 'mod' => 0, 'repeat' => false]];
  $screen->handleEvent($zeroDown);
  $screen->handleEvent($zeroUp);
  boardCheck((new ReflectionProperty(Controller::class, 'boardAssigneeOpen'))->getValue() === null && count((new ReflectionProperty(Controller::class, 'boardIssueKeys'))->getValue()) === count($filterIssues), '0 restores everybody and closes the selector.');
  $screen->handleEvent($f);
  $screen->handleEvent($escape);
  boardCheck((new ReflectionProperty(Controller::class, 'boardAssigneeOpen'))->getValue() === null && count((new ReflectionProperty(Controller::class, 'boardIssueKeys'))->getValue()) === count($filterIssues), 'Escape closes the assignee selector without changing the filter.');
  (new ReflectionMethod(Controller::class, 'showBoardResult'))->invoke(null, ['configuration' => $result['configuration'], 'issues' => []], false);
  $emptyBoardGrid = new Grid($screen->layout->grid()->width, $screen->layout->grid()->height);
  $screen->paint($emptyBoardGrid);
  boardCheck((new ReflectionProperty(Controller::class, 'boardColumnTitles'))->getValue() === [] && str_contains(boardRow($emptyBoardGrid, 0, 2, $emptyBoardGrid->width()), 'This sprint has no issues'), 'An empty sprint shows its empty message without placeholder columns.');
} finally {
  $window->close();
  $font->close();
  $ttf->close();
  $sdl->close();
}
$data->clearCaches();
boardCheck(!BoardResultCache::has('7', '70'), 'Clearing Jira caches discards cached Board results.');
echo "Board view OK\n";
