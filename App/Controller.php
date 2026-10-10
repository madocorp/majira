<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Adf;
use SPTK\App;
use SPTK\Core\Window;
use SPTK\Core\Style;
use SPTK\Events\EventContext;
use SPTK\Events\EventDefinition;
use SPTK\Layout\{LayoutLeaf, LayoutNode, LayoutSeparator};
use SPTK\Widgets\DateSelector\DateSelector;
use SPTK\Widgets\Button\Button;
use SPTK\Widgets\Input\Input;
use SPTK\Widgets\List\ListView;
use SPTK\Widgets\Table\Table;
use SPTK\Widgets\Text\Text;
use SPTK\Widgets\TextEditor\TextEditor;
use SPTK\Widgets\Title\Title;

/** Connects SPTK tile screens to cached Jira workflows. */
class Controller {

  private const LAST_JQL_ITEM = "\0last-jql";

  private static Window $window;
  private static JiraData $data;
  private static array $issues = [];
  private static ?LayoutNode $boardPickerClosed = null;
  private static ?LayoutNode $boardPickerOpen = null;
  private static ?LayoutNode $boardBody = null;
  private static ?LayoutNode $boardAssigneeClosed = null;
  private static ?LayoutNode $boardAssigneeOpen = null;
  private static ?LayoutLeaf $boardAssigneeReturnLeaf = null;
  private static array $boardAssigneeButtons = [];
  private static array $boardAssignees = [];
  private static ?array $boardResult = null;
  private static string $boardAssigneeKey = '';
  private static int $boardAssigneePage = 0;
  private static ?ListView $boardPickerList = null;
  private static string $boardPickerKind = '';
  private static ?string $boardPickerSelection = null;
  private static array $boardIssueKeys = [];
  private const BOARD_VISIBLE_COLUMNS = 4;
  private const BOARD_ASSIGNEES_PER_PAGE = 5;
  private static array $boardColumnLeaves = [];
  private static array $boardColumnNodes = [];
  private static array $boardColumnStacks = [];
  private static array $boardCardIndexes = [];
  private static array $boardColumnTitles = [];
  private static int $boardCurrentColumn = 0;
  private static int $boardViewportStart = 0;
  private static array $meta = [];
  private static array $ticket = [];
  private static string $ticketKey = '';
  private const TICKET_CARDS = ['description', 'comments', 'attachments', 'related'];
  private static array $ticketExpanded = [];
  private static array $ticketCompact = [];
  private static array $ticketProperties = [];
  private static string $activeTicketCard = 'description';
  private static array $relatedTicketKeys = [];
  private static ?LayoutNode $ticketPropertiesPane = null;
  private static ?LayoutNode $ticketHistoryPane = null;
  private static ?LayoutLeaf $ticketHistoryLeaf = null;
  private static ?ListView $ticketHistoryList = null;
  private static array $ticketHistoryRows = [];
  private static array $ticketBeforeHistory = [];
  private static string $ticketKeyBeforeHistory = '';
  private static bool $ticketHistoryOpen = false;
  private static ?LayoutLeaf $listTableLeaf = null;
  private static ?LayoutLeaf $listFocusBeforeSidebar = null;
  private static ?LayoutNode $listTableOnly = null;
  private static ?LayoutNode $listSidebar = null;
  private static bool $sidebarOpen = false;
  private static ?string $sidebarSelection = null;
  private const FILTER_TILES = ['name', 'project', 'board', 'sprint', 'search', 'updated', 'created', 'status', 'type', 'priority', 'assignee'];
  private static array $filterExpanded = [];
  private static array $filterCompact = [];
  private static array $filterWidgets = [];
  private static ?LayoutNode $filterTiles = null;
  private static array $dateSlots = [];
  private static string $activeFilterTile = 'name';
  private static string $editingFilterName = '';
  private static string $editingJqlText = '';
  private static ?LayoutNode $jqlFilterView = null;
  private static bool $showingJqlFilter = false;

  /** Restore cached data after the window opens. */
  public static function initialize(EventContext $event): void {
    self::$window = array_values(App::eventLoop()->windows())[0];
    self::$data = new JiraData();
    self::prepareListSidebar();
    self::prepareBoard();
    self::prepareFilterTiles();
    self::prepareTicketTiles();
    $cached = TicketCache::load();
    self::$issues = is_array($cached['issues'] ?? null) ? $cached['issues'] : [];
    self::$meta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
    $settings = Settings::load();
    foreach (['jira-site' => 'site', 'jira-email' => 'email'] as $id => $key) {
      self::input('settings', $id)->setValue((string)($settings[$key] ?? ''));
    }
    self::text('settings', 'settings-unlicense')->setText(trim((string)file_get_contents(APP_DIR . '/UNLICENSE')));
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::renderNavigation();
    self::renderBoardSelectors();
    $boardState = BoardState::load();
    $cachedBoard = BoardResultCache::load((string)$boardState['boardId'], (string)$boardState['sprintId']);
    if ($cachedBoard !== null) {
      self::showBoardResult($cachedBoard, false);
    }
    self::renderTickets();
    self::restoreLastTicket();
    $filters = FilterState::load();
    $selected = FilterState::customFilterByName($filters['selectedCustomFilter'], $filters);
    if ($selected !== null) {
      self::stageNamedFilter($selected['name'], $selected, true);
    } else {
      self::renderFilters();
    }
    self::continuousStatus(Settings::isConfigured() ? 'Cached Jira data ready. Refresh for new results.' : 'Set Jira connection in Settings.');
    if (Settings::isConfigured()) {
      if (ProjectCache::load() === []) {
        self::reloadProjects($event);
      }
    }
    if (Settings::isConfigured() && !empty(self::$meta['hasMore'])) {
      self::reportTicketCount();
    }
  }

  /** Save edited JQL and fetch its first result page. */
  public static function applyJql(EventContext $event): void {
    $jql = trim(self::input('list', 'jql')->getValue());
    if ($jql === (new JqlBuilder())->current()) {
      return;
    }
    $filters = FilterState::load();
    $filters['customJql'] = $jql;
    $filters['customJqlEdited'] = true;
    $filters['mode'] = 'jql';
    $filters['lastJql'] = $jql;
    $filters['selectedCustomFilter'] = '';
    FilterState::save($filters);
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::renderSidebarFilters();
    self::applyFilterAndFocusTable();
  }

  /** Toggle saved filters beside the ticket table. */
  public static function toggleFilterSidebar(EventContext $event): void {
    if (self::$sidebarOpen) {
      self::closeFilterSidebar();
      return;
    }
    $screen = self::$window->screen('list');
    self::$listFocusBeforeSidebar = $screen->selectedLeaf();
    if ($screen->activeLeaf() === self::$listTableLeaf) {
      $screen->release('cancel');
    }
    if (!$screen->layout->replaceChild(self::$listTableOnly, self::$listSidebar)) {
      throw new \LogicException('List table layout is missing.');
    }
    self::$sidebarOpen = true;
    $screen->setLayout($screen->layout);
    self::renderSidebarFilters();
    self::$window->refreshLayout();
    foreach ($screen->layout->leaves() as $leaf) {
      if ($leaf->instance() === self::list('list', 'sidebar-filters')) {
        $screen->activateLeaf($leaf);
        self::$window->refreshLayout();
        return;
      }
    }
    throw new \LogicException('Sidebar filter list is missing.');
  }

  /** Open filters from the active table after its text input event has passed. */
  public static function filterShortcut(EventContext $event): bool {
    $active = self::$window->screen('list')->activeLeaf();
    if ($active !== self::$listTableLeaf) {
      return false;
    }
    self::toggleFilterSidebar($event);
    return true;
  }

  /** Keep the XML sidebar ready while starting the List with a full-width table. */
  private static function prepareListSidebar(): void {
    $screen = self::$window->screen('list');
    self::$listSidebar = $screen->layout->findNode('list-body');
    if (self::$listSidebar === null) {
      throw new \LogicException('List body layout is missing.');
    }
    foreach (self::$listSidebar->leaves() as $leaf) {
      if ($leaf->instance() === $screen->widget('tickets')) {
        self::$listTableLeaf = $leaf;
        break;
      }
    }
    if (self::$listTableLeaf === null) {
      throw new \LogicException('List table tile is missing.');
    }
    self::$listTableOnly = new LayoutNode('horizontal', '1*', '1*');
    self::$listTableOnly->addLeaf(self::$listTableLeaf);
    if (!$screen->layout->replaceChild(self::$listSidebar, self::$listTableOnly)) {
      throw new \LogicException('List body layout is missing.');
    }
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** Keep the Board picker and column area replaceable without touching List filters. */
  private static function prepareBoard(): void {
    $layout = self::$window->screen('board')->layout;
    self::$boardPickerClosed = $layout->findNode('board-picker-slot');
    self::$boardAssigneeClosed = $layout->findNode('board-assignee-slot');
    self::$boardBody = $layout->findNode('board-body');
    if (self::$boardPickerClosed === null || self::$boardAssigneeClosed === null || self::$boardBody === null) {
      throw new \LogicException('Board layout is missing its picker, assignee slot, or body.');
    }
  }

  public static function openBoardProjects(EventContext $event): void {
    if (!ProjectCache::load() && Settings::isConfigured() && !self::request('GET projects', fn() => self::$data->projects())) {
      return;
    }
    $items = [['value' => '', 'label' => 'No project selected']];
    foreach (ProjectCache::load() as $project) {
      $items[] = ['value' => $project['key'], 'label' => $project['key'] . '  ' . $project['name']];
    }
    self::openBoardPicker('project', $items, (string)BoardState::load()['projectKey']);
  }

  public static function openBoardBoards(EventContext $event): void {
    $project = (string)BoardState::load()['projectKey'];
    if ($project === '') {
      self::status('Choose a Board project first.');
      return;
    }
    if (!BoardCache::has($project) && !self::request('GET boards for ' . $project, fn() => self::$data->boards($project))) {
      return;
    }
    $items = [['value' => '', 'label' => 'No board selected']];
    foreach (BoardCache::load($project) as $board) {
      $items[] = ['value' => $board['id'], 'label' => $board['name']];
    }
    self::openBoardPicker('board', $items, (string)BoardState::load()['boardId']);
  }

  public static function openBoardSprints(EventContext $event): void {
    $board = (string)BoardState::load()['boardId'];
    if ($board === '') {
      self::status('Choose a Board board first.');
      return;
    }
    if (!SprintCache::has($board) && !self::request('GET sprints for board ' . $board, fn() => self::$data->sprints($board))) {
      return;
    }
    $items = [['value' => '', 'label' => 'No sprint selected']];
    foreach (SprintCache::load($board) as $sprint) {
      $items[] = ['value' => $sprint['id'], 'label' => $sprint['name'] . '  ' . $sprint['state']];
    }
    self::openBoardPicker('sprint', $items, (string)BoardState::load()['sprintId']);
  }

  /** Show one list in the otherwise collapsed row below the selector buttons. */
  private static function openBoardPicker(string $kind, array $items, string $selected): void {
    self::closeBoardPicker();
    $list = new ListView($items, filterable: false, searchable: true, title: ucfirst($kind));
    if (in_array($selected, $list->values(), true)) {
      $list->setValue($selected);
    }
    $node = new LayoutNode('vertical', '1*', '9');
    $leaf = new LayoutLeaf('List', '1*', '1*', $list, [
      new EventDefinition('keyDown', 'enter', self::class . '::chooseBoardPicker'),
      new EventDefinition('deactivate', null, self::class . '::finishBoardPicker'),
    ]);
    $node->addLeaf($leaf);
    $screen = self::$window->screen('board');
    if (!$screen->layout->replaceChild(self::$boardPickerClosed, $node)) {
      throw new \LogicException('Board picker slot is missing.');
    }
    self::$boardPickerOpen = $node;
    self::$boardPickerList = $list;
    self::$boardPickerKind = $kind;
    self::$boardPickerSelection = null;
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
    $screen->activateLeaf($leaf);
    self::$window->refreshLayout();
  }

  /** Return accepts the highlighted value; Escape only closes the picker. */
  public static function chooseBoardPicker(EventContext $event): bool {
    self::$boardPickerSelection = self::$boardPickerList?->getValue();
    self::$window->screen('board')->release('cancel');
    return true;
  }

  public static function finishBoardPicker(EventContext $event): void {
    $kind = self::$boardPickerKind;
    $selection = self::$boardPickerSelection;
    self::closeBoardPicker();
    if ($selection === null || $kind === '') {
      return;
    }
    $state = BoardState::load();
    $key = match ($kind) {
      'project' => 'projectKey',
      'board' => 'boardId',
      'sprint' => 'sprintId',
    };
    if ($state[$key] !== $selection) {
      $state[$key] = $selection;
      if ($kind === 'project') {
        $state['boardId'] = '';
        $state['sprintId'] = '';
      } else if ($kind === 'board') {
        $state['sprintId'] = '';
      }
      BoardState::save($state);
    }
    self::renderBoardSelectors();
    self::clearBoardBody();
    if ($kind === 'sprint' && $selection !== '') {
      self::loadBoard(false);
    }
  }

  private static function closeBoardPicker(): void {
    if (self::$boardPickerOpen === null) {
      return;
    }
    $screen = self::$window->screen('board');
    $open = self::$boardPickerOpen;
    $kind = self::$boardPickerKind;
    self::$boardPickerOpen = null;
    self::$boardPickerList = null;
    self::$boardPickerKind = '';
    self::$boardPickerSelection = null;
    if (!$screen->layout->replaceChild($open, self::$boardPickerClosed)) {
      throw new \LogicException('Open Board picker is missing.');
    }
    $screen->setLayout($screen->layout);
    $button = $screen->widget('board-' . $kind . '-button');
    foreach ($screen->layout->leaves() as $leaf) {
      if ($leaf->instance() === $button) {
        $screen->selectLeaf($leaf);
        break;
      }
    }
    self::$window->refreshLayout();
  }

  private static function renderBoardSelectors(): void {
    $state = BoardState::load();
    $project = (string)$state['projectKey'];
    $board = (string)$state['boardId'];
    $sprint = (string)$state['sprintId'];
    $projectLabel = $project ?: 'Select';
    $boardLabel = $board ?: 'Select';
    foreach (BoardCache::load($project) as $row) {
      if ($row['id'] === $board) {
        $boardLabel = $row['name'];
        break;
      }
    }
    $sprintLabel = $sprint ?: 'Select';
    foreach (SprintCache::load($board) as $row) {
      if ($row['id'] === $sprint) {
        $sprintLabel = $row['name'];
        break;
      }
    }
    foreach (['project' => $projectLabel, 'board' => $boardLabel, 'sprint' => $sprintLabel] as $kind => $label) {
      $button = self::$window->screen('board')->widget('board-' . $kind . '-button');
      if ($button instanceof Button) {
        $button->setLabel(ucfirst($kind) . ': ' . $label);
      }
    }
  }

  /** Identify a Jira user consistently even when display names are duplicated. */
  private static function boardIssueAssigneeKey(array $issue): string {
    $assignee = $issue['fields']['assignee'] ?? null;
    if (!is_array($assignee)) {
      return '';
    }
    $id = trim((string)($assignee['accountId'] ?? ''));
    $name = trim((string)($assignee['displayName'] ?? ''));
    return $id !== '' ? 'id:' . $id : ($name !== '' ? 'name:' . $name : '');
  }

  /** Count assignees across the loaded sprint and order them by ticket count. */
  private static function boardAssigneeChoices(array $issues): array {
    $people = [];
    foreach ($issues as $issue) {
      if (!is_array($issue) || trim((string)($issue['key'] ?? '')) === '' || ($key = self::boardIssueAssigneeKey($issue)) === '') {
        continue;
      }
      $assignee = $issue['fields']['assignee'];
      $people[$key] ??= [
        'key' => $key,
        'name' => trim((string)($assignee['displayName'] ?? '')) ?: trim((string)($assignee['accountId'] ?? '')),
        'count' => 0,
      ];
      $people[$key]['count']++;
    }
    uasort($people, fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));
    return array_values($people);
  }

  /** Open the assignee row, or show its next five people when already open. */
  public static function openBoardAssigneeFilter(EventContext $event): bool {
    if (self::$boardResult === null) {
      self::status('Load a Board sprint before filtering.');
      return true;
    }
    if (self::$boardAssigneeOpen === null) {
      self::$boardAssigneeReturnLeaf = self::$window->screen('board')->selectedLeaf();
      self::$boardAssigneePage = 0;
    } else {
      self::$boardAssigneePage = (self::$boardAssigneePage + 1) % max(1, (int)ceil(count(self::$boardAssignees) / self::BOARD_ASSIGNEES_PER_PAGE));
    }
    self::renderBoardAssigneePage();
    return true;
  }

  private static function renderBoardAssigneePage(): void {
    $entries = array_merge([
      ['key' => '', 'name' => 'Everybody', 'count' => count(self::$boardResult['issues'])],
    ], array_slice(self::$boardAssignees, self::$boardAssigneePage * self::BOARD_ASSIGNEES_PER_PAGE, self::BOARD_ASSIGNEES_PER_PAGE));
    $row = new LayoutNode('horizontal', '1*', '1');
    self::$boardAssigneeButtons = [];
    $first = null;
    foreach ($entries as $index => $entry) {
      $hotkey = (string)$index;
      $label = $entry['name'] . ' (' . $entry['count'] . ')';
      $button = new Button($label, $hotkey, self::class . '::selectBoardAssigneeFilter', new Style());
      $button->setActivated($entry['key'] === self::$boardAssigneeKey);
      $button->setTips($hotkey . ': ' . $label . '. Return filters the board.');
      $leaf = new LayoutLeaf('Button', '1*', '1', $button);
      $row->addLeaf($leaf);
      self::$boardAssigneeButtons[$hotkey] = ['button' => $button, 'key' => $entry['key']];
      $first ??= $leaf;
    }
    $screen = self::$window->screen('board');
    if (!$screen->layout->replaceChild(self::$boardAssigneeOpen ?? self::$boardAssigneeClosed, $row)) {
      throw new \LogicException('Board assignee slot is missing.');
    }
    self::$boardAssigneeOpen = $row;
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
    $screen->selectLeaf($first);
  }

  /** Apply one visible assignee button, including 0 for Everybody. */
  public static function selectBoardAssigneeFilter(EventContext $event): void {
    foreach (self::$boardAssigneeButtons as $entry) {
      if ($entry['button'] !== $event->widget) {
        continue;
      }
      self::$boardAssigneeKey = $entry['key'];
      self::closeBoardAssigneeFilter($event);
      if (self::$boardResult !== null) {
        self::showBoardResult(self::$boardResult, false);
      }
      return;
    }
  }

  /** Hide the selector row and return focus to the tile that opened it. */
  public static function closeBoardAssigneeFilter(EventContext $event): bool {
    if (self::$boardAssigneeOpen === null) {
      return false;
    }
    $screen = self::$window->screen('board');
    if (!$screen->layout->replaceChild(self::$boardAssigneeOpen, self::$boardAssigneeClosed)) {
      throw new \LogicException('Open Board assignee row is missing.');
    }
    self::$boardAssigneeOpen = null;
    self::$boardAssigneeButtons = [];
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
    if (self::$boardAssigneeReturnLeaf !== null && in_array(self::$boardAssigneeReturnLeaf, $screen->layout->movementLeaves(), true)) {
      $screen->selectLeaf(self::$boardAssigneeReturnLeaf);
    }
    self::$boardAssigneeReturnLeaf = null;
    return true;
  }

  private static function clearBoardBody(string $message = 'Select a project, board and sprint above to load its issues.'): void {
    self::closeBoardAssigneeFilter(new EventContext('activate'));
    self::$boardResult = null;
    self::$boardAssignees = [];
    self::$boardAssigneeKey = '';
    self::$boardAssigneePage = 0;
    if (self::boardCardLocation(self::$window->screen('board')->activeLeaf()) !== null) {
      self::$window->screen('board')->release('cancel');
    }
    self::$boardIssueKeys = [];
    self::$boardColumnLeaves = [];
    self::$boardColumnNodes = [];
    self::$boardColumnStacks = [];
    self::$boardCardIndexes = [];
    self::$boardColumnTitles = [];
    self::$boardCurrentColumn = 0;
    self::$boardViewportStart = 0;
    self::$window->screen('board')->statusBar->clear();
    self::replaceBoardBody(new Text($message));
  }

  private static function replaceBoardBody(Text|LayoutNode $content): void {
    $node = $content instanceof LayoutNode ? $content : new LayoutNode('vertical', '1*', '1*');
    if ($content instanceof Text) {
      $node->addLeaf(new LayoutLeaf('Text', '1*', '1*', $content));
    }
    $screen = self::$window->screen('board');
    if (!$screen->layout->replaceChild(self::$boardBody, $node)) {
      throw new \LogicException('Board body is missing.');
    }
    self::$boardBody = $node;
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** Remember the item chosen by Return before the list clears its search query. */
  public static function selectSidebarFilter(EventContext $event): void {
    $value = self::list('list', 'sidebar-filters')->getValue();
    self::$sidebarSelection = is_string($value) ? $value : null;
  }

  /** Close on Escape; apply only when Return marked a selection. */
  public static function finishSidebarFilter(EventContext $event): void {
    $selection = self::$sidebarSelection;
    self::$sidebarSelection = null;
    self::closeFilterSidebar();
    if ($selection === self::LAST_JQL_ITEM && self::activateLastJql()) {
      self::applyFilterAndFocusTable();
    } else if ($selection !== null && $selection !== self::LAST_JQL_ITEM && self::activateCustomFilter($selection)) {
      self::applyFilterAndFocusTable();
    }
  }

  /** Restore the last JQL edited in the List input. */
  private static function activateLastJql(): bool {
    $filters = FilterState::load();
    if ($filters['lastJql'] === '') {
      self::status('No previous JQL edit to restore.');
      return false;
    }
    $filters['customJql'] = $filters['lastJql'];
    $filters['customJqlEdited'] = true;
    $filters['mode'] = 'jql';
    $filters['selectedCustomFilter'] = '';
    FilterState::save($filters);
    self::input('list', 'jql')->setValue($filters['lastJql']);
    self::status('Restored Last JQL.');
    return true;
  }

  /** Fetch a fresh first page. */
  public static function refreshTickets(EventContext $event): void {
    if (self::refreshShortcutBlocked('list')) {
      return;
    }
    if (self::loadTickets(false)) {
      self::reportTicketCount();
    }
  }

  /** Load the selected sprint with columns defined by its board. */
  public static function refreshBoard(EventContext $event): void {
    self::loadBoard(true);
  }

  /** Use a saved sprint result on selection, or request fresh data. */
  private static function loadBoard(bool $refresh): void {
    $state = BoardState::load();
    $board = (string)$state['boardId'];
    $sprint = (string)$state['sprintId'];
    if ($board === '' || $sprint === '') {
      self::status('Select a board and sprint above first.');
      return;
    }
    if (!$refresh && ($cached = BoardResultCache::load($board, $sprint)) !== null) {
      self::showBoardResult($cached);
      return;
    }
    self::request('GET board ' . $board . ' sprint ' . $sprint, function() use ($board, $sprint): void {
      self::showBoardResult(self::$data->boardSprint($board, $sprint, true));
    });
  }

  /** Render the configured columns and optional result message. */
  private static function showBoardResult(array $result, bool $announce = true): void {
    self::closeBoardAssigneeFilter(new EventContext('activate'));
    self::$boardResult = $result;
    self::$boardAssignees = self::boardAssigneeChoices($result['issues']);
    if (self::$boardAssigneeKey !== '' && !in_array(self::$boardAssigneeKey, array_column(self::$boardAssignees, 'key'), true)) {
      self::$boardAssigneeKey = '';
    }
    $issues = self::$boardAssigneeKey === '' ? $result['issues'] : array_values(array_filter(
      $result['issues'], fn($issue): bool => is_array($issue) && self::boardIssueAssigneeKey($issue) === self::$boardAssigneeKey,
    ));
    $columns = BoardView::columns($result['configuration'], $issues);
    if ($columns === []) {
      if (self::boardCardLocation(self::$window->screen('board')->activeLeaf()) !== null) {
        self::$window->screen('board')->release('cancel');
      }
      self::$boardIssueKeys = [];
      self::$boardColumnLeaves = [];
      self::$boardColumnNodes = [];
      self::$boardColumnStacks = [];
      self::$boardCardIndexes = [];
      self::$boardColumnTitles = [];
      self::$boardCurrentColumn = 0;
      self::$boardViewportStart = 0;
      self::$window->screen('board')->statusBar->clear();
      self::replaceBoardBody(new Text('This sprint has no issues or board columns.'));
      return;
    }
    self::renderBoardColumns($columns);
    if ($announce) {
      self::backgroundStatus('Loaded ' . count($result['issues']) . ' sprint issues in ' . count($columns) . ' columns.');
    }
  }

  /** Build fixed-height issue tiles and keep each column's scroll position. */
  private static function renderBoardColumns(array $columns): void {
    $screen = self::$window->screen('board');
    $selected = self::boardCardLocation($screen->selectedLeaf());
    $active = $selected !== null && $screen->activeLeaf() === $screen->selectedLeaf();
    if ($active) {
      $screen->release('cancel');
    }
    $previousIndexes = self::$boardCardIndexes;
    $previousOffsets = array_map(fn(LayoutNode $node): int => $node->scrollOffset(), self::$boardColumnStacks);
    self::$boardIssueKeys = [];
    self::$boardColumnLeaves = [];
    self::$boardColumnNodes = [];
    self::$boardColumnStacks = [];
    self::$boardCardIndexes = [];
    self::$boardColumnTitles = [];
    $cardSeparatorColor = (new Style())->background->darkened(0.6);
    foreach ($columns as $columnIndex => $column) {
      self::$boardColumnTitles[] = (string)$column['name'];
      $cards = [];
      foreach ($column['issues'] as $issue) {
        $key = (string)($issue['key'] ?? '');
        if ($key === '') {
          continue;
        }
        $summary = trim((string)($issue['fields']['summary'] ?? ''));
        $assignee = trim((string)($issue['fields']['assignee']['displayName'] ?? ''));
        $type = trim((string)($issue['fields']['issuetype']['name'] ?? ''));
        $card = new BoardTicketCard($key, $summary, $assignee, $type);
        $cards[] = new LayoutLeaf('BoardTicketCard', '1*', (string)BoardTicketCard::HEIGHT, $card, [
          new EventDefinition('activate', null, self::class . '::openBoardIssue'),
          new EventDefinition('select', null, self::class . '::boardColumnSelected'),
        ]);
        self::$boardIssueKeys[$key] = true;
      }
      $issueCount = count($cards);
      if ($cards === []) {
        $cards[] = new LayoutLeaf('BoardTicketCard', '1*', (string)BoardTicketCard::HEIGHT, new BoardTicketCard('', 'No tickets', ''), [
          new EventDefinition('select', null, self::class . '::boardColumnSelected'),
        ]);
      }
      $stack = new LayoutNode('vertical', '1*', '1*');
      $stack->setSeparatorColor($cardSeparatorColor);
      $stack->setOverflow(true);
      $stack->setScrollOffset($previousOffsets[$columnIndex] ?? 0);
      foreach ($cards as $index => $card) {
        if ($index > 0 && $issueCount > 0) {
          $stack->addSeparator(new LayoutSeparator());
        }
        $stack->addLeaf($card);
      }
      $stack->addLeaf(new LayoutLeaf('BoardColumnFill', '1*', '4', new BoardColumnFill(), [], false));
      $stack->addLeaf(new LayoutLeaf('BoardColumnFill', '1*', '1*', new BoardColumnFill(), [], false));
      $node = new LayoutNode('vertical', '1*', '1*');
      $node->addLeaf(new LayoutLeaf('BoardColumnHeader', '1*', '1', new BoardColumnHeader((string)$column['name'], $issueCount, $cards), [], false));
      $node->addNode($stack);
      self::$boardColumnLeaves[] = $cards;
      self::$boardColumnNodes[] = $node;
      self::$boardColumnStacks[] = $stack;
      self::$boardCardIndexes[] = min($previousIndexes[$columnIndex] ?? 0, count($cards) - 1);
    }
    self::$boardViewportStart = min(self::$boardViewportStart, max(0, count(self::$boardColumnNodes) - self::BOARD_VISIBLE_COLUMNS));
    self::$boardCurrentColumn = min(self::$boardCurrentColumn, count(self::$boardColumnNodes) - 1);
    if ($selected !== null) {
      [$columnIndex, $cardIndex] = $selected;
      $columnIndex = min($columnIndex, count(self::$boardColumnNodes) - 1);
      self::$boardCurrentColumn = $columnIndex;
      self::$boardCardIndexes[$columnIndex] = min($cardIndex, count(self::$boardColumnLeaves[$columnIndex]) - 1);
      self::$boardViewportStart = min(self::$boardViewportStart, $columnIndex);
      self::$boardViewportStart = max(self::$boardViewportStart, $columnIndex - self::BOARD_VISIBLE_COLUMNS + 1);
    }
    self::renderBoardViewport();
    if ($selected !== null) {
      $leaf = self::$boardColumnLeaves[self::$boardCurrentColumn][self::$boardCardIndexes[self::$boardCurrentColumn]];
      if ($active) {
        $screen->activateLeaf($leaf);
      } else {
        $screen->selectLeaf($leaf);
      }
      self::ensureBoardCardVisible(self::$boardCurrentColumn, self::$boardCardIndexes[self::$boardCurrentColumn]);
      self::$window->refreshLayout();
    }
  }

  /** Display at most four columns while retaining every column's list. */
  private static function renderBoardViewport(): void {
    $start = self::$boardViewportStart;
    $visible = array_slice(self::$boardColumnNodes, $start, self::BOARD_VISIBLE_COLUMNS);
    $columns = new LayoutNode('horizontal', '1*', '1*');
    foreach ($visible as $index => $node) {
      $columns->addNode($node);
      if ($index < count($visible) - 1) {
        $columns->addSeparator(new LayoutSeparator());
      }
    }
    self::replaceBoardBody($columns);
    self::updateBoardStatusMap();
  }

  /** Show all board columns in a passive status message. */
  private static function updateBoardStatusMap(): void {
    $titles = [];
    foreach (self::$boardColumnTitles as $index => $title) {
      $label = trim(preg_replace('/\s+/', ' ', $title));
      $titles[] = $index === self::$boardCurrentColumn ? '** ' . $label . ' **' : $label;
    }
    if (self::$boardAssigneeKey !== '') {
      foreach (self::$boardAssignees as $person) {
        if ($person['key'] === self::$boardAssigneeKey) {
          $titles[] = 'Assignee: ' . $person['name'];
          break;
        }
      }
    }
    self::$window->screen('board')->statusBar->notice(implode(' | ', $titles), 'continuous');
  }

  /** Find the column and card index of a board tile. */
  private static function boardCardLocation(?LayoutLeaf $target): ?array {
    if ($target === null) {
      return null;
    }
    foreach (self::$boardColumnLeaves as $column => $cards) {
      $index = array_search($target, $cards, true);
      if ($index !== false) {
        return [$column, $index];
      }
    }
    return null;
  }

  /** Shift the card stack by whole cards until the chosen card is visible. */
  private static function ensureBoardCardVisible(int $column, int $index): void {
    $stack = self::$boardColumnStacks[$column];
    $slots = self::boardVisibleCardSlots($stack);
    $top = intdiv($stack->scrollOffset(), BoardTicketCard::HEIGHT + 1);
    if ($index < $top) {
      $top = $index;
    } else if ($index >= $top + $slots) {
      $top = $index - $slots + 1;
    } else {
      return;
    }
    $stack->setScrollOffset($top * (BoardTicketCard::HEIGHT + 1));
    self::$window->refreshLayout();
  }

  private static function boardVisibleCardSlots(LayoutNode $stack): int {
    return max(1, intdiv(max(0, $stack->grid()->height) + 1, BoardTicketCard::HEIGHT + 1));
  }

  /** Move between board columns, shifting the four-column viewport at an edge. */
  private static function moveBoardColumn(int $step): bool {
    $screen = self::$window->screen('board');
    $location = self::boardCardLocation($screen->selectedLeaf());
    if ($location === null) {
      return false;
    }
    [$current] = $location;
    $next = $current + $step;
    if (!isset(self::$boardColumnNodes[$next])) {
      return false;
    }
    self::$boardCurrentColumn = $next;
    if ($next < self::$boardViewportStart) {
      self::$boardViewportStart = $next;
      self::renderBoardViewport();
    } else if ($next >= self::$boardViewportStart + self::BOARD_VISIBLE_COLUMNS) {
      self::$boardViewportStart = $next - self::BOARD_VISIBLE_COLUMNS + 1;
      self::renderBoardViewport();
    }
    $targetIndex = self::$boardCardIndexes[$next];
    $screen->selectLeaf(self::$boardColumnLeaves[$next][$targetIndex]);
    self::ensureBoardCardVisible($next, $targetIndex);
    self::updateBoardStatusMap();
    return true;
  }

  public static function boardColumnLeft(EventContext $event): bool {
    return self::moveBoardColumn(-1);
  }

  public static function boardColumnRight(EventContext $event): bool {
    return self::moveBoardColumn(1);
  }

  /** Jump to the visible edge, then advance one viewport of columns. */
  private static function pageBoardColumn(int $step): bool {
    $location = self::boardCardLocation(self::$window->screen('board')->selectedLeaf());
    if ($location === null) {
      return false;
    }
    [$current] = $location;
    $last = count(self::$boardColumnNodes) - 1;
    $edge = $step > 0
      ? min($last, self::$boardViewportStart + self::BOARD_VISIBLE_COLUMNS - 1)
      : self::$boardViewportStart;
    $next = $step > 0
      ? ($current < $edge ? $edge : min($last, $edge + self::BOARD_VISIBLE_COLUMNS))
      : ($current > $edge ? $edge : max(0, $edge - self::BOARD_VISIBLE_COLUMNS));
    return $next === $current || self::moveBoardColumn($next - $current);
  }

  public static function boardColumnHome(EventContext $event): bool {
    return self::pageBoardColumn(-1);
  }

  public static function boardColumnEnd(EventContext $event): bool {
    return self::pageBoardColumn(1);
  }

  /** Move between cards within a column and scroll at its visible edge. */
  private static function moveBoardCard(int $step): bool {
    $screen = self::$window->screen('board');
    $location = self::boardCardLocation($screen->selectedLeaf());
    if ($location === null) {
      return false;
    }
    [$column, $index] = $location;
    $next = $index + $step;
    if (!isset(self::$boardColumnLeaves[$column][$next])) {
      return $step > 0;
    }
    self::$boardCardIndexes[$column] = $next;
    $screen->selectLeaf(self::$boardColumnLeaves[$column][$next]);
    self::ensureBoardCardVisible($column, $next);
    return true;
  }

  public static function boardCardUp(EventContext $event): bool {
    return self::moveBoardCard(-1);
  }

  public static function boardCardDown(EventContext $event): bool {
    return self::moveBoardCard(1);
  }

  /** Jump to the visible edge, then advance one full page in this column. */
  private static function pageBoardCard(int $step): bool {
    $screen = self::$window->screen('board');
    $location = self::boardCardLocation($screen->selectedLeaf());
    if ($location === null) {
      return false;
    }
    [$column, $index] = $location;
    $stack = self::$boardColumnStacks[$column];
    $slots = self::boardVisibleCardSlots($stack);
    $top = intdiv($stack->scrollOffset(), BoardTicketCard::HEIGHT + 1);
    $last = count(self::$boardColumnLeaves[$column]) - 1;
    $edge = $step > 0 ? min($last, $top + $slots - 1) : $top;
    $next = $step > 0
      ? ($index < $edge ? $edge : min($last, $edge + $slots))
      : ($index > $edge ? $edge : max(0, $edge - $slots));
    if ($next !== $index) {
      self::$boardCardIndexes[$column] = $next;
      $screen->selectLeaf(self::$boardColumnLeaves[$column][$next]);
      self::ensureBoardCardVisible($column, $next);
    }
    return true;
  }

  public static function boardCardPageUp(EventContext $event): bool {
    return self::pageBoardCard(-1);
  }

  public static function boardCardPageDown(EventContext $event): bool {
    return self::pageBoardCard(1);
  }

  /** Track the selected card and its column for horizontal navigation. */
  public static function boardColumnSelected(EventContext $event): void {
    foreach (self::$boardColumnLeaves as $column => $cards) {
      foreach ($cards as $index => $leaf) {
        if ($leaf->instance() === $event->widget) {
          self::$boardCurrentColumn = $column;
          self::$boardCardIndexes[$column] = $index;
          self::ensureBoardCardVisible($column, $index);
          self::updateBoardStatusMap();
          return;
        }
      }
    }
  }

  /** Open a card in the existing Ticket screen. */
  public static function openBoardIssue(EventContext $event): void {
    $key = $event->widget instanceof BoardTicketCard ? $event->widget->key() : null;
    if (is_string($key) && isset(self::$boardIssueKeys[$key])) {
      self::openTicket($key);
    }
  }

  /** Append the next available page. */
  public static function moreTickets(EventContext $event): void {
    if (empty(self::$meta['hasMore'])) {
      self::status('No more ticket results.');
      return;
    }
    if (self::loadTickets(true)) {
      self::reportTicketCount();
    }
  }

  /** Open a ticket by its Jira key. */
  public static function openKey(EventContext $event): void {
    $key = strtoupper(trim(self::input('ticket', 'open-key')->getValue()));
    if (!preg_match('/^[A-Z][A-Z0-9_]*-[0-9]+$/', $key)) {
      self::status('Enter a Jira key such as AP-123.', true);
      return;
    }
    self::openTicket($key);
  }

  /** Open the highlighted ticket. */
  public static function openSelectedTicket(EventContext $event): void {
    $key = (string)(self::$issues[self::table('list', 'tickets')->cursorRow()]['key'] ?? '');
    if ($key !== '') {
      self::openTicket($key);
    }
  }

  /** Prepare the ticket creation screen. */
  public static function newTicket(EventContext $event): void {
    if (self::$ticketHistoryOpen) {
      self::cancelTicketHistory($event);
    }
    $project = (string)(Settings::load()['projectKey'] ?? '');
    if ($project === '') {
      self::status('Select a project before creating a ticket.', true);
      return;
    }
    self::title('create', 'create-project')->setText('New ticket in ' . $project);
    $types = self::$data->cachedIssueTypes($project);
    if ($types === []) {
      self::loadIssueTypes($project);
    } else {
      self::renderIssueTypes($types);
    }
    self::$window->setCurrentScreenId('create');
  }

  /** Fetch issue types when the selected project has no cached choices. */
  private static function loadIssueTypes(string $project): void {
    self::request('GET issue types for ' . $project, function() use ($project): void {
      self::renderIssueTypes(self::$data->issueTypes($project));
    });
  }

  /** Create the ticket and open its freshly loaded detail. */
  public static function createTicket(EventContext $event): void {
    $project = (string)(Settings::load()['projectKey'] ?? '');
    $selected = self::list('create', 'create-types')->getValue();
    $summary = trim(self::input('create', 'create-summary')->getValue());
    $description = trim(self::editor('create', 'create-description')->getValue());
    $type = null;
    foreach (self::$data->cachedIssueTypes($project) as $candidate) {
      if (($candidate['id'] ?: $candidate['name']) === $selected) {
        $type = $candidate;
        break;
      }
    }
    if ($project === '' || $type === null || $summary === '') {
      self::status('Select an issue type and enter a summary.', true);
      return;
    }
    $key = '';
    $created = self::request('POST new ticket to ' . $project, function() use ($project, $type, $summary, $description, &$key): void {
      $key = self::$data->createTicket($project, $type, $summary, $description);
      self::input('create', 'create-summary')->setValue('');
      self::editor('create', 'create-description')->setValue('');
    });
    if ($created) {
      self::openTicket($key, true);
    }
  }

  /** Return to the result list. */
  public static function showList(EventContext $event): void {
    self::$window->setCurrentScreenId('list');
  }

  /** Select a project and reset its dependent board and sprint. */
  public static function selectProject(EventContext $event): void {
    $value = self::list('filters', 'projects')->getValue();
    if (!is_string($value)) {
      return;
    }
    $settings = Settings::load();
    if (($settings['projectKey'] ?? '') !== $value) {
      $settings['projectKey'] = $value;
      $settings['boardId'] = '';
      $settings['sprintId'] = '';
      Settings::save($settings);
    }
    self::renderNavigation();
    self::loadMissingNavigationChoices(true);
    self::renderFilterOptions();
    self::saveGeneratedFilter($event);
    self::continuousStatus('Project: ' . ($value === '' ? 'Any project' : $value));
    self::refreshFilterSummaries();
  }

  /** Select a board and reset its sprint. */
  public static function selectBoard(EventContext $event): void {
    $value = self::list('filters', 'boards')->getValue();
    if (!is_string($value)) {
      return;
    }
    $settings = Settings::load();
    $settings['boardId'] = $value;
    $settings['sprintId'] = '';
    Settings::save($settings);
    self::renderNavigation();
    self::loadMissingNavigationChoices();
    self::renderFilterOptions();
    self::saveGeneratedFilter($event);
    self::continuousStatus('Board for sprint choices: ' . ($value ?: 'None selected'));
    self::refreshFilterSummaries();
  }

  /** Select a sprint. */
  public static function selectSprint(EventContext $event): void {
    $value = self::list('filters', 'sprints')->getValue();
    if (!is_string($value)) {
      return;
    }
    $settings = Settings::load();
    $settings['sprintId'] = $value;
    Settings::save($settings);
    self::renderNavigation();
    self::saveGeneratedFilter($event);
    self::continuousStatus('Sprint: ' . ($value ?: 'Any sprint'));
    self::refreshFilterSummaries();
  }

  /** Refresh the project cache from Jira. */
  public static function reloadProjects(EventContext $event): bool {
    return self::request('GET projects', function(): void {
      self::$data->projects();
      self::renderNavigation();
    });
  }

  /** Fetch newly selected board and sprint lists once, including empty results. */
  private static function loadMissingNavigationChoices(bool $includeAnyProject = false): void {
    $settings = Settings::load();
    $project = (string)($settings['projectKey'] ?? '');
    if (($project !== '' || $includeAnyProject) && !BoardCache::has($project)) {
      self::request('GET boards for ' . ($project === '' ? 'any project' : $project), function() use ($project): void {
        self::$data->boards($project);
        self::renderNavigation();
      });
    }
    $board = (string)($settings['boardId'] ?? '');
    if ($board !== '' && !SprintCache::has($board)) {
      self::request('GET sprints for board ' . $board, function() use ($board): void {
        self::$data->sprints($board);
        self::renderNavigation();
      });
    }
    $filterCache = FilterCache::load($project);
    $scope = $project === '' ? 'global' : 'project';
    if ((!$filterCache['_loaded'] || $filterCache['priorityScope'] !== $scope)) {
      self::request('GET filter options for ' . ($project === '' ? 'any project' : $project), function() use ($project): void {
        self::$data->filterOptions($project);
        self::renderFilterOptions();
      });
    }
  }

  /** Save connection settings and clear data belonging to the old account. */
  public static function saveSettings(EventContext $event): void {
    $settings = Settings::load();
    $next = [
      'site' => rtrim(trim(self::input('settings', 'jira-site')->getValue()), '/'),
      'email' => trim(self::input('settings', 'jira-email')->getValue()),
      'apiToken' => trim(self::input('settings', 'jira-token')->getValue()) ?: (string)($settings['apiToken'] ?? ''),
    ];
    if (!Settings::isConfigured($next) || !preg_match('~^https://[^/]+$~i', $next['site'])) {
      self::status('Enter an HTTPS Jira site, email and API token.', true);
      return;
    }
    $changed = (string)($settings['site'] ?? '') !== $next['site']
      || (string)($settings['email'] ?? '') !== $next['email']
      || (string)($settings['apiToken'] ?? '') !== $next['apiToken'];
    $settings = array_replace($settings, $next);
    if (!Settings::save($settings)) {
      self::status('Could not save Jira settings.', true);
      return;
    }
    self::input('settings', 'jira-token')->setValue('');
    if ($changed) {
      self::$data->clearCaches();
      BoardState::save([]);
      self::renderBoardSelectors();
      self::clearBoardBody();
      TicketHistory::clear();
      self::clearTicket();
      $settings['projectKey'] = '';
      $settings['boardId'] = '';
      $settings['sprintId'] = '';
      Settings::save($settings);
      self::$issues = [];
      self::$meta = [];
      self::renderNavigation();
      self::renderTickets();
      self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    }
    if ($changed && !self::reloadProjects($event)) {
      return;
    }
    self::status('Jira connection saved.');
  }

  /** Test the saved Jira connection. */
  public static function testConnection(EventContext $event): void {
    self::request('GET current Jira user', function(): void {
      $user = self::$data->client()->myself();
      self::status('Connected as ' . (string)($user['displayName'] ?? $user['emailAddress'] ?? 'Jira user'));
    });
  }

  /** Clear response caches while retaining user settings. */
  public static function clearCaches(EventContext $event): void {
    self::$data->clearCaches();
    self::$issues = [];
    self::$meta = [];
    $boardState = BoardState::load();
    $boardMessage = $boardState['boardId'] !== '' && $boardState['sprintId'] !== ''
      ? 'Board cache cleared. Press R to reload this sprint.'
      : 'Select a project, board and sprint above to load its issues.';
    self::clearBoardBody($boardMessage);
    self::renderNavigation();
    self::renderTickets();
    self::status('Jira caches cleared.');
  }

  /** Stop the application event loop. */
  public static function quit(EventContext $event): void {
    App::eventLoop()->stop();
  }

  /** Discard filter choice data; fetch projects so the first choice remains available. */
  public static function clearFilterChoiceCache(EventContext $event): void {
    self::$data->clearFilterChoices();
    self::renderNavigation();
    self::renderFilterOptions();
    if (!Settings::isConfigured()) {
      self::status('Filter choices cleared. Connect to Jira to reload projects.');
      return;
    }
    if (self::request('GET projects', function(): void {
      self::$data->projects();
      self::renderNavigation();
    })) {
      self::status('Filter choices cleared. Confirm a project to reload its choices.');
      self::$window->refreshLayout();
    }
  }

  /** Apply the already saved JQL. */
  public static function applyFilters(EventContext $event): void {
    $jql = trim((new JqlBuilder())->current());
    if ($jql === '') {
      self::status('Enter a JQL query or choose at least one filter first.', true);
      return;
    }
    $filters = FilterState::load();
    if ($filters['mode'] === 'builder') {
      foreach (['updated', 'created'] as $field) {
        if ($filters[$field] !== '' && $filters[$field . 'To'] !== '' && $filters[$field] > $filters[$field . 'To']) {
          self::status(ucfirst($field) . ': From must be on or before Through.', true);
          return;
        }
      }
    }
    self::input('list', 'jql')->setValue($jql);
    self::$window->setCurrentScreenId('list');
    self::applyFilterAndFocusTable();
  }

  /** Save builder choices; the editable JQL view is a separate mode. */
  public static function saveGeneratedFilter(EventContext $event): void {
    $filters = FilterState::load();
    if ($filters['mode'] !== 'builder') {
      return;
    }
    $filters['search']['text'] = trim(self::input('filters', 'search')->getValue());
    foreach (['updated', 'created'] as $field) {
      $filters[$field] = self::$dateSlots[$field]['from']['enabled'] ? self::date('filters', $field . '-date')->getValue() : '';
      $filters[$field . 'To'] = self::$dateSlots[$field]['to']['enabled'] ? self::date('filters', $field . '-to-date')->getValue() : '';
    }
    $project = (string)(Settings::load()['projectKey'] ?? '');
    $choices = FilterCache::load($project);
    if ($choices['_loaded'] && $choices['priorityScope'] === ($project === '' ? 'global' : 'project')) {
      foreach (['status', 'type', 'priority', 'assignee'] as $group) {
        $filters[$group] = self::list('filters', $group . '-options')->getValue();
      }
    }
    $jql = (new JqlBuilder())->generatedFor($filters);
    $filters['customJql'] = '';
    $filters['customJqlEdited'] = false;
    $name = self::$editingFilterName;
    if ($name !== '') {
      foreach ($filters['customFilters'] as &$row) {
        if ($row['name'] === $name) {
          $row['jql'] = $jql;
          $row['form'] = FilterState::formValues($filters);
          $row['scope'] = FilterState::scopeValues(Settings::load());
          break;
        }
      }
      unset($row);
      $filters['selectedCustomFilter'] = $name;
    } else {
      $filters['selectedCustomFilter'] = '';
    }
    FilterState::save($filters);
    self::input('list', 'jql')->setValue($jql);
    self::refreshFilterSummaries();
    self::$window->refreshLayout();
  }

  /** Ignore navigation keys in the search input. */
  public static function searchChanged(EventContext $event): void {
    if (trim(self::input('filters', 'search')->getValue()) !== FilterState::load()['search']['text']) {
      self::saveGeneratedFilter($event);
    }
  }

  /** Persist manual JQL edits as soon as the editor changes. */
  public static function saveEditedFilterJql(EventContext $event): void {
    if (FilterState::load()['mode'] !== 'jql') {
      return;
    }
    $text = self::editor('filters', 'filter-jql')->getValue();
    if ($text === self::$editingJqlText) {
      return;
    }
    self::$editingJqlText = $text;
    $jql = trim($text);
    $filters = FilterState::load();
    $name = self::$editingFilterName;
    if ($name !== '') {
      foreach ($filters['customFilters'] as &$row) {
        if ($row['name'] === $name) {
          $row['jql'] = $jql;
          break;
        }
      }
      unset($row);
      $filters['selectedCustomFilter'] = $name;
      $filters['customJql'] = '';
      $filters['customJqlEdited'] = false;
    } else {
      $filters['customJql'] = $jql;
      $filters['customJqlEdited'] = true;
      $filters['selectedCustomFilter'] = '';
    }
    FilterState::save($filters);
    self::input('list', 'jql')->setValue($jql);
    self::refreshFilterSummaries();
  }

  /** Convert the current builder filter into an independently editable query. */
  public static function switchToJqlMode(EventContext $event): void {
    $filters = FilterState::load();
    if ($filters['mode'] === 'jql') {
      self::status('This filter is already in JQL mode.');
      return;
    }
    $jql = (new JqlBuilder())->generatedFor($filters);
    $filters['mode'] = 'jql';
    $name = self::$editingFilterName;
    if ($name !== '') {
      foreach ($filters['customFilters'] as &$row) {
        if ($row['name'] === $name) {
          $row['mode'] = 'jql';
          $row['jql'] = $jql;
          $row['form'] = FilterState::formValues($filters);
          $row['scope'] = FilterState::scopeValues(Settings::load());
          break;
        }
      }
      unset($row);
    } else {
      $filters['customJql'] = $jql;
      $filters['customJqlEdited'] = true;
    }
    FilterState::save($filters);
    self::renderJqlEditor();
    self::renderFilterMode();
    self::input('list', 'jql')->setValue($jql);
    self::$window->refreshLayout();
    self::status('JQL mode saved. Recreate this filter to use the builder again.');
  }

  /** Reset the current saved or unnamed filter without changing its identity. */
  public static function clearFilter(EventContext $event): void {
    $filters = FilterState::clearCurrent(FilterState::load());
    Settings::save(array_replace(Settings::load(), FilterState::scopeValues([])));
    FilterState::save($filters);
    self::renderNavigation();
    self::renderFilters();
    self::input('list', 'jql')->setValue('');
    self::status('Filter cleared.');
    self::$window->refreshLayout();
  }

  /** Add a blank named filter and show its empty form. */
  public static function newFilter(EventContext $event): void {
    $filters = FilterState::load();
    if (self::nameInUse('new', $filters)) {
      self::status('A filter named new already exists.', true);
      return;
    }
    $form = FilterState::formValues(FilterState::defaults());
    $filters = array_replace($filters, $form);
    $filters['customJql'] = '';
    $filters['customJqlEdited'] = false;
    $filters['mode'] = 'builder';
    $filters['selectedCustomFilter'] = 'new';
    $scope = FilterState::scopeValues([]);
    $filters['customFilters'][] = ['name' => 'new', 'jql' => '', 'mode' => 'builder', 'form' => $form, 'scope' => $scope];
    Settings::save(array_replace(Settings::load(), $scope));
    FilterState::save($filters);
    self::$editingFilterName = 'new';
    self::renderNavigation();
    self::renderFilters();
    self::input('filters', 'custom-name')->setValue('new', true);
    self::input('list', 'jql')->setValue('');
    self::openFilterTile('name', false);
    self::$window->screen('filters')->activateLeaf(self::expandedFilterLeaf('name'));
    self::status('New filter added.');
    self::$window->refreshLayout();
  }

  /** Restore the form recorded for the highlighted saved filter. */
  public static function selectCustomFilter(EventContext $event): void {
    $name = self::list('filters', 'custom-filters')->getValue();
    if (!is_string($name) || $name === '') {
      return;
    }
    $filters = FilterState::load();
    $row = FilterState::customFilterByName($name, $filters);
    if ($row === null) {
      return;
    }
    self::stageNamedFilter($name, $row, false);
    self::$window->refreshLayout();
  }

  /** Preserve the saved filter order after Shift+Up/Down. */
  public static function reorderCustomFilters(EventContext $event): void {
    $filters = FilterState::reorderSavedFilters(FilterState::load(), self::list('filters', 'custom-filters')->values());
    FilterState::save($filters);
    self::renderSidebarFilters();
  }

  /** Restore a saved query and its available form choices. */
  private static function stageNamedFilter(string $name, array $row, bool $renderSaved): void {
    $filters = FilterState::load();
    $filters = array_replace($filters, $row['form'] ?? FilterState::formValues(FilterState::defaults()));
    $filters['customJql'] = '';
    $filters['customJqlEdited'] = false;
    $filters['mode'] = $row['mode'];
    $filters['selectedCustomFilter'] = $name;
    Settings::save(array_replace(Settings::load(), $row['scope'] ?? FilterState::scopeValues([])));
    FilterState::save($filters);
    self::renderNavigation();
    self::$editingFilterName = $name;
    self::renderFilters($renderSaved);
    self::openFilterTile('name', false);
    self::input('filters', 'custom-name')->setValue($name);
    self::input('list', 'jql')->setValue($row['jql']);
  }

  /** Save the current filter under a name, or rename its selected entry. */
  public static function renameCustomFilter(EventContext $event): void {
    $name = trim(self::input('filters', 'custom-name')->getValue());
    $old = self::$editingFilterName;
    if ($name === $old) {
      return;
    }
    if ($name === '') {
      self::input('filters', 'custom-name')->setValue($old);
      self::status('Filter name cannot be empty.', true);
      return;
    }
    $filters = FilterState::load();
    if (self::nameInUse($name, $filters, $old)) {
      self::input('filters', 'custom-name')->setValue($old);
      self::status('A filter named ' . $name . ' already exists.', true);
      return;
    }
    if ($old === '') {
      $filters['customFilters'][] = [
        'name' => $name,
        'jql' => (new JqlBuilder())->current(),
        'mode' => $filters['mode'],
        'form' => FilterState::formValues($filters),
        'scope' => FilterState::scopeValues(Settings::load()),
      ];
      $filters['customJql'] = '';
      $filters['customJqlEdited'] = false;
    } else {
      foreach ($filters['customFilters'] as &$row) {
        if ($row['name'] === $old) {
          $row['name'] = $name;
          break;
        }
      }
      unset($row);
    }
    $filters['selectedCustomFilter'] = $name;
    FilterState::save($filters);
    self::$editingFilterName = $name;
    self::renderCustomFilters();
    self::refreshFilterSummaries();
    self::$window->refreshLayout();
    self::status(($old === '' ? 'Saved filter: ' : 'Renamed filter: ') . $name);
  }

  private static function nameInUse(string $name, array $filters, string $except = ''): bool {
    foreach ($filters['customFilters'] as $row) {
      if ($row['name'] !== $except && mb_strtolower($row['name']) === mb_strtolower($name)) {
        return true;
      }
    }
    return false;
  }

  /** Select a saved JQL query and update the List input. */
  private static function activateCustomFilter(string $name): bool {
    $filters = FilterState::load();
    $selected = FilterState::customFilterByName($name, $filters);
    if ($selected === null) {
      return false;
    }
    if (trim((string)$selected['jql']) === '') {
      self::status('Save this filter before using it.', true);
      return false;
    }
    self::stageNamedFilter($name, $selected, true);
    self::status('Using saved filter: ' . $name);
    return true;
  }

  /** Delete the highlighted filter and load the adjacent saved filter. */
  public static function deleteCustomFilter(EventContext $event): void {
    $name = self::list('filters', 'custom-filters')->getValue();
    if (!is_string($name) || $name === '') {
      return;
    }
    $filters = FilterState::load();
    if (FilterState::customFilterByName($name, $filters) === null) {
      return;
    }
    $filters = FilterState::removeSavedFilter($filters, $name);
    $nextName = $filters['selectedCustomFilter'];
    if ($nextName !== '') {
      FilterState::save($filters);
      self::stageNamedFilter($nextName, FilterState::customFilterByName($nextName, $filters), true);
    } else {
      $filters = FilterState::clearCurrent($filters);
      Settings::save(array_replace(Settings::load(), FilterState::scopeValues([])));
      FilterState::save($filters);
      self::$editingFilterName = '';
      self::renderNavigation();
      self::renderFilters();
      self::openFilterTile('name', false);
      self::input('list', 'jql')->setValue('');
    }
    self::status('Deleted filter: ' . $name);
    self::$window->refreshLayout();
  }

  /** Reload the current ticket from Jira. */
  public static function refreshTicket(EventContext $event): void {
    if (self::refreshShortcutBlocked('ticket')) {
      return;
    }
    if (self::$ticketHistoryOpen) {
      self::cancelTicketHistory($event);
    }
    if (self::$ticketKey !== '') {
      self::openTicket(self::$ticketKey, true);
    }
  }

  /** Keep R available on tables without intercepting text or List input. */
  private static function refreshShortcutBlocked(string $screenId): bool {
    $widget = self::$window->screen($screenId)->activeLeaf()?->instance();
    return $widget instanceof Input || $widget instanceof TextEditor || $widget instanceof ListView;
  }

  /** Save the summary field. */
  public static function saveSummary(EventContext $event): void {
    $summary = trim(self::input('ticket', 'ticket-summary')->getValue());
    if (self::$ticketKey === '' || $summary === '') {
      self::status('Summary cannot be empty.', true);
      return;
    }
    if ($summary === (string)(self::$ticket['fields']['summary'] ?? '')) {
      return;
    }
    self::writeTicket(['summary' => $summary], 'PUT summary');
  }

  /** Save the description field. */
  public static function saveDescription(EventContext $event): void {
    $description = self::editor('ticket', 'ticket-description')->getValue();
    if (self::$ticketKey !== '' && $description !== Adf::toText(self::$ticket['fields']['description'] ?? null)) {
      self::writeTicket(['description' => Adf::fromMarkdown($description)], 'PUT description');
    }
  }

  /** Open the related issue selected in the ticket card. */
  public static function openRelatedTicket(EventContext $event): void {
    $key = self::$relatedTicketKeys[self::table('ticket', 'ticket-related')->cursorRow()] ?? '';
    if ($key !== '') {
      self::openTicket($key);
    }
  }

  /** Discard changes to the displayed comment history. */
  public static function restoreTicketComments(EventContext $event): void {
    self::editor('ticket', 'ticket-comments')->setValue(self::$ticketKey === '' ? '' : self::ticketCommentsText());
  }

  /** Load tickets and show them only when the request succeeds. */
  private static function loadTickets(bool $more): bool {
    $jql = (new JqlBuilder())->current();
    if (!$more) {
      self::$issues = [];
      self::$meta = [];
      self::renderTickets();
    }
    return self::request('POST Jira ticket search' . ($more ? ' (more)' : ''), function() use ($jql, $more): void {
      $result = self::$data->tickets($jql, $more);
      self::$issues = $result['issues'];
      self::$meta = $result['meta'];
      self::renderTickets();
    });
  }

  /** Activate the table only when a successful filter search returns tickets. */
  private static function applyFilterAndFocusTable(): void {
    if (!self::loadTickets(false)) {
      return;
    }
    if (self::$issues !== []) {
      self::$window->screen('list')->activateLeaf(self::$listTableLeaf);
    }
    self::reportTicketCount();
  }

  /** Show the loaded count and how to fetch another page when one exists. */
  private static function reportTicketCount(): void {
    $count = count(self::$issues);
    if (!empty(self::$meta['hasMore'])) {
      self::listTicketStatus('Showing ' . $count . ' tickets; more available (M: next page).');
    } else {
      self::listTicketStatus($count === 0 ? 'No tickets found.' : 'Showing all ' . $count . ' tickets.');
    }
    self::$window->refreshLayout();
  }

  /** Open a cached or fresh ticket. */
  private static function openTicket(string $key, bool $refresh = false): void {
    if (self::$ticketHistoryOpen) {
      self::cancelTicketHistory(new EventContext('cancel'));
    }
    $label = !$refresh && self::$data->hasCachedTicket($key) ? 'Open cached ' : 'GET Jira ticket ';
    self::request($label . $key, function() use ($key, $refresh): void {
      self::$ticket = self::$data->ticket($key, $refresh);
      self::$ticketKey = $key;
      if ($refresh) {
        self::refreshTicketRow();
      }
      self::renderTicket();
      self::resetTicketDeck();
      self::$window->setCurrentScreenId('ticket');
      $screen = self::$window->screen('ticket');
      $screen->selectLeaf($screen->layout->findNode('ticket-left')->leaves()[0]);
      self::$window->refreshLayout();
      TicketHistory::add(self::$ticket);
    });
  }

  /** Restore the most recently opened ticket without a startup Jira request. */
  private static function restoreLastTicket(): void {
    $last = TicketHistory::load()[0] ?? null;
    if (!is_array($last) || ($key = (string)($last['key'] ?? '')) === '') {
      return;
    }
    self::$ticketKey = $key;
    self::$ticket = self::$data->cachedTicket($key) ?? [
      'key' => $key,
      'fields' => ['summary' => (string)($last['title'] ?? '')],
    ];
    self::renderTicket();
    $screen = self::$window->screen('ticket');
    $screen->selectLeaf($screen->layout->findNode('ticket-left')->leaves()[0]);
  }

  /** Empty ticket details after the Jira account changes. */
  private static function clearTicket(): void {
    self::$ticket = [];
    self::$ticketKey = '';
    self::renderTicket();
    self::resetTicketDeck();
  }

  /** Update Jira and refresh the issue cache. */
  private static function writeTicket(array $fields, string $label): void {
    $saved = self::request($label . ' ' . self::$ticketKey, function() use ($fields): void {
      self::$data->client()->updateIssueFields(self::$ticketKey, $fields);
    });
    if ($saved) {
      self::openTicket(self::$ticketKey, true);
    }
  }

  /** Paint request activity before synchronous Jira I/O. */
  private static function request(string $label, callable $operation): bool {
    self::progressStatus($label . ' ...');
    self::$window->refreshLayout();
    try {
      $operation();
      $bar = self::$window->screen(self::$window->currentScreenId())?->statusBar;
      if ($bar?->locked() || !in_array($bar?->behavior(), ['modal', 'confirmation', 'background'], true)) {
        self::backgroundStatus($label . ' complete.');
      }
    } catch (\Throwable $error) {
      self::status($label . ' failed: ' . $error->getMessage(), true);
      self::$window->refreshLayout();
      return false;
    }
    self::$window->refreshLayout();
    return true;
  }

  /** Show an action result or error until the user acknowledges it. */
  private static function status(string $message, bool $error = false): void {
    $activeScreen = self::$window->currentScreenId();
    foreach (['list', 'filters', 'board', 'ticket', 'settings', 'create'] as $screen) {
      $bar = self::$window->screen($screen)?->statusBar;
      if ($screen !== $activeScreen) {
        continue;
      }
      if ($error) {
        $bar?->error($message);
      } else {
        $bar?->notice($message);
      }
    }
  }

  /** Keep guidance on the current screen after temporary messages. */
  private static function continuousStatus(string $message): void {
    self::$window->screen(self::$window->currentScreenId())?->statusBar?->notice($message, 'continuous');
  }

  /** Keep pagination guidance on List even when another screen is active. */
  private static function listTicketStatus(string $message): void {
    self::$window->screen('list')?->statusBar?->notice($message, 'continuous');
  }

  /** Block input during synchronous Jira communication. */
  private static function progressStatus(string $message): void {
    self::$window->screen(self::$window->currentScreenId())?->statusBar?->info($message, 'modal', lock: true);
  }

  /** Show a short-lived completion message without moving focus. */
  private static function backgroundStatus(string $message): void {
    self::$window->screen(self::$window->currentScreenId())?->statusBar?->info($message, 'background');
  }

  /** Populate project, board and sprint tiles from caches. */
  private static function renderNavigation(): void {
    $settings = Settings::load();
    $project = (string)($settings['projectKey'] ?? '');
    $board = (string)($settings['boardId'] ?? '');
    $sprint = (string)($settings['sprintId'] ?? '');
    $projects = [['value' => '', 'label' => 'Any project']];
    foreach (ProjectCache::load() as $row) {
      $projects[] = ['value' => $row['key'], 'label' => $row['key'] . '  ' . $row['name']];
    }
    self::list('filters', 'projects')->setItems($projects);
    self::selectKnown('filters', 'projects', $project);
    $boards = [['value' => '', 'label' => 'No board selected']];
    foreach (BoardCache::load($project) as $row) {
      $boards[] = ['value' => $row['id'], 'label' => $row['name']];
    }
    self::list('filters', 'boards')->setItems($boards);
    self::selectKnown('filters', 'boards', $board);
    $sprints = [
      ['value' => '', 'label' => 'Any sprint'],
      ['value' => JqlBuilder::NO_SPRINTS, 'label' => 'No sprint'],
    ];
    foreach (SprintCache::load($board) as $row) {
      $sprints[] = ['value' => $row['id'], 'label' => $row['name'] . '  ' . $row['state']];
    }
    self::list('filters', 'sprints')->setItems($sprints);
    self::selectKnown('filters', 'sprints', $sprint);
    self::refreshFilterSummaries();
  }

  /** Restore one known list selection. */
  private static function selectKnown(string $screen, string $id, string $value): void {
    if (in_array($value, self::list($screen, $id)->values(), true)) {
      self::list($screen, $id)->setValue($value);
    }
  }

  /** Present cached ticket rows. */
  private static function renderTickets(): void {
    $table = self::table('list', 'tickets');
    $cursor = $table->cursorRow();
    $rows = [];
    foreach (self::$issues as $issue) {
      $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
      $rows[] = [
        (string)($issue['key'] ?? ''),
        (string)($fields['summary'] ?? ''),
        (string)($fields['status']['name'] ?? ''),
        (string)($fields['assignee']['displayName'] ?? 'Unassigned'),
      ];
    }
    $table->setRows(['Key', 'Summary', 'Status', 'Assignee'], $rows);
    $table->setCursor($cursor);
  }

  /** Display creatable issue types without another Jira request. */
  private static function renderIssueTypes(array $types): void {
    $items = [];
    foreach ($types as $type) {
      $items[] = ['value' => $type['id'] ?: $type['name'], 'label' => $type['name']];
    }
    self::list('create', 'create-types')->setItems($items);
  }

  /** Keep a visible ticket row in sync after a field update. */
  private static function refreshTicketRow(): void {
    foreach (self::$issues as $index => $issue) {
      if (($issue['key'] ?? '') === self::$ticketKey) {
        self::$issues[$index] = self::$ticket;
        $cached = TicketCache::load();
        TicketCache::save(is_array($cached['state'] ?? null) ? $cached['state'] : [], self::$issues, self::$meta);
        self::renderTickets();
        return;
      }
    }
  }

  /** Restore search, date, and choice filters. */
  private static function renderFilters(bool $renderSaved = true): void {
    $filters = FilterState::load();
    self::$editingFilterName = $filters['selectedCustomFilter'];
    self::input('filters', 'custom-name')->setValue(self::$editingFilterName);
    self::input('filters', 'search')->setValue($filters['search']['text']);
    foreach (['updated', 'created'] as $field) {
      self::date('filters', $field . '-date')->setValue($filters[$field] ?: date('Y-m-d'));
      self::date('filters', $field . '-to-date')->setValue($filters[$field . 'To'] ?: self::date('filters', $field . '-date')->getValue());
      self::showDateSlot($field, 'from', $filters[$field] !== '');
      self::showDateSlot($field, 'to', $filters[$field . 'To'] !== '');
    }
    self::renderFilterOptions();
    if ($renderSaved) {
      self::renderCustomFilters();
    } else {
      self::renderSidebarFilters();
    }
    if ($filters['mode'] === 'jql') {
      self::renderJqlEditor();
    }
    self::renderFilterMode();
    self::refreshFilterSummaries();
  }

  /** Restore the JQL text when selecting a query-mode filter. */
  private static function renderJqlEditor(): void {
    $jql = self::displayedJql();
    self::$editingJqlText = JqlFormatter::format($jql);
    self::editor('filters', 'filter-jql')->setValue(self::$editingJqlText);
  }

  private static function displayedJql(): string {
    $filters = FilterState::load();
    $row = FilterState::customFilterByName(self::$editingFilterName, $filters);
    return $row['jql'] ?? ($filters['customJqlEdited'] || $filters['customJql'] !== '' ? $filters['customJql'] : (new JqlBuilder())->generatedFor($filters));
  }

  /** Show the builder or the JQL editor for the selected filter. */
  private static function renderFilterMode(): void {
    $jql = FilterState::load()['mode'] === 'jql';
    if ($jql === self::$showingJqlFilter) {
      return;
    }
    $screen = self::$window->screen('filters');
    $middle = $screen->layout->findNode('filter-middle');
    $tiles = self::$filterTiles;
    if (!$middle->replaceChild($jql ? $tiles : self::$jqlFilterView, $jql ? self::$jqlFilterView : $tiles)) {
      throw new \LogicException('Filter mode layout is missing.');
    }
    self::$showingJqlFilter = $jql;
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** Keep one full-height filter tile and two-line summaries for the others. */
  private static function prepareFilterTiles(): void {
    $screen = self::$window->screen('filters');
    $column = $screen->layout->findNode('filter-tiles');
    if ($column === null) {
      throw new \LogicException('Filter tile column is missing.');
    }
    self::$filterTiles = $column;
    $nameNode = $column->findNode('filter-name');
    $nameInput = $nameNode === null ? null : $nameNode->leaves()[0]->instance();
    if (!$nameInput instanceof Input) {
      throw new \LogicException('Filter name input is missing.');
    }
    $editor = new TextEditor('', new Style(), true, 8, null, 'JQL');
    $editor->setId('filter-jql');
    self::$filterWidgets['filter-jql'] = $editor;
    self::$jqlFilterView = new LayoutNode('vertical', '1*', '1*', id: 'filter-jql-mode');
    // Both views use the same input, so an in-progress name survives mode changes.
    self::$jqlFilterView->addLeaf(new LayoutLeaf('Input', '1*', 'auto', $nameInput, [
      new EventDefinition('deactivate', null, self::class . '::renameCustomFilter'),
    ]));
    self::$jqlFilterView->addSeparator(new LayoutSeparator());
    self::$jqlFilterView->addLeaf(new LayoutLeaf('TextEditor', '1*', '1*', $editor, [
      new EventDefinition('keyUp', null, self::class . '::saveEditedFilterJql'),
      new EventDefinition('deactivate', null, self::class . '::saveEditedFilterJql'),
    ]));
    foreach (self::FILTER_TILES as $key) {
      $node = $column->findNode('filter-' . $key);
      if ($node === null) {
        throw new \LogicException('Filter tile is missing: ' . $key);
      }
      self::$filterExpanded[$key] = $node;
      foreach ($node->leaves() as $leaf) {
        $id = $leaf->instance()->id();
        if ($id !== null) {
          self::$filterWidgets[$id] = $leaf->instance();
        }
      }
      $summary = new FilterSummary($key === 'name' ? 'Filter name' : ucfirst($key));
      $summary->setId('filter-summary-' . $key);
      $compact = new LayoutLeaf('FilterSummary', '1*', '2', $summary, [
        new EventDefinition('select', null, self::class . '::expandFilterTile'),
      ]);
      self::$filterCompact[$key] = $compact;
      if ($key !== self::$activeFilterTile) {
        $column->replaceChild($node, $compact);
      }
    }
    foreach (['updated', 'created'] as $field) {
      foreach (['from' => $field . '-date', 'to' => $field . '-to-date'] as $bound => $id) {
        $slot = self::$filterExpanded[$field]->findNode($field . '-' . $bound . '-slot');
        $date = $slot->leaves()[0];
        $placeholder = new FilterSummary(ucfirst($bound));
        $placeholder->setId('date-placeholder-' . $field . '-' . $bound);
        $placeholder->setValue('Enter to add date');
        $empty = new LayoutLeaf('FilterSummary', '1*', '1*', $placeholder);
        $slot->replaceChild($date, $empty);
        self::$dateSlots[$field][$bound] = ['slot' => $slot, 'date' => $date, 'empty' => $empty, 'enabled' => false];
      }
    }
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
    self::refreshFilterSummaries();
  }

  /** Enter on an empty date slot adds its calendar. */
  public static function addDateFilter(EventContext $event): bool {
    foreach (self::$dateSlots as $field => $bounds) {
      foreach ($bounds as $bound => $slot) {
        if ($event->widget === $slot['empty']->instance() && !$slot['enabled']) {
          $id = $field . ($bound === 'to' ? '-to-date' : '-date');
          self::date('filters', $id)->setValue(date('Y-m-d'));
          self::showDateSlot($field, $bound, true, true);
          self::saveGeneratedFilter($event);
          return true;
        }
      }
    }
    return false;
  }

  /** Delete on a date calendar returns its slot to the empty state. */
  public static function removeDateFilter(EventContext $event): bool {
    foreach (self::$dateSlots as $field => $bounds) {
      foreach ($bounds as $bound => $slot) {
        if ($event->widget === $slot['date']->instance() && $slot['enabled']) {
          self::$window->screen('filters')->release('cancel');
          self::showDateSlot($field, $bound, false, true);
          self::saveGeneratedFilter($event);
          return true;
        }
      }
    }
    return false;
  }

  /** Refresh the collapsed date summary after a calendar choice. */
  public static function dateSelectionChanged(EventContext $event): void {
    self::saveGeneratedFilter($event);
  }

  private static function showDateSlot(string $field, string $bound, bool $enabled, bool $focus = false): void {
    $slot = &self::$dateSlots[$field][$bound];
    if ($slot['enabled'] === $enabled) {
      return;
    }
    $previous = $slot[$slot['enabled'] ? 'date' : 'empty'];
    $next = $slot[$enabled ? 'date' : 'empty'];
    $slot['slot']->replaceChild($previous, $next);
    $slot['enabled'] = $enabled;
    if (self::$activeFilterTile === $field) {
      $screen = self::$window->screen('filters');
      $screen->setLayout($screen->layout);
      if ($focus) {
        if ($enabled) {
          $screen->activateLeaf($next);
        } else {
          $screen->selectLeaf($next);
        }
      }
      self::$window->refreshLayout();
    }
  }

  /** Expand the summary reached with an arrow key. */
  public static function expandFilterTile(EventContext $event): void {
    $id = $event->widget?->id() ?? '';
    $key = str_starts_with($id, 'filter-summary-') ? substr($id, strlen('filter-summary-')) : '';
    self::openFilterTile($key, true);
  }

  /** Open one card, keeping list focus when a saved filter changes. */
  private static function openFilterTile(string $key, bool $focus): bool {
    if (self::$showingJqlFilter || !isset(self::$filterExpanded[$key]) || $key === self::$activeFilterTile) {
      return false;
    }
    $previous = self::$activeFilterTile;
    $screen = self::$window->screen('filters');
    $column = $screen->layout->findNode('filter-tiles');
    $column->replaceChild(self::$filterExpanded[$previous], self::$filterCompact[$previous]);
    $column->replaceChild(self::$filterCompact[$key], self::$filterExpanded[$key]);
    self::$activeFilterTile = $key;
    $screen->setLayout($screen->layout);
    if ($focus) {
      $screen->selectLeaf(self::expandedFilterLeaf($key));
    }
    self::refreshFilterSummaries();
    self::$window->refreshLayout();
    return true;
  }

  /** Right from saved filters enters the currently open filter tile. */
  public static function focusExpandedFilterFromSaved(EventContext $event): bool {
    if ($event->widget !== self::list('filters', 'custom-filters')) {
      return false;
    }
    return self::focusExpandedFilter();
  }

  /** Left from the action column enters the currently open filter tile. */
  public static function focusExpandedFilterFromActions(EventContext $event): bool {
    $screen = self::$window->screen('filters');
    $actions = $screen->layout->findNode('filter-actions');
    foreach ($actions?->leaves() ?? [] as $leaf) {
      if ($leaf === $screen->selectedLeaf()) {
        return self::focusExpandedFilter();
      }
    }
    return false;
  }

  /** Select the expanded widget while leaving it ready for Return. */
  private static function focusExpandedFilter(): bool {
    $screen = self::$window->screen('filters');
    $screen->selectLeaf(self::$showingJqlFilter ? self::$jqlFilterView->leaves()[1] : self::expandedFilterLeaf(self::$activeFilterTile));
    return true;
  }

  private static function expandedFilterLeaf(string $key): LayoutLeaf {
    return self::$filterExpanded[$key]->leaves()[0];
  }

  /** Show each filter's current choice beneath its name while collapsed. */
  private static function refreshFilterSummaries(): void {
    if (self::$filterCompact === []) {
      return;
    }
    $settings = Settings::load();
    $values = [
      'name' => self::input('filters', 'custom-name')->getValue(),
      'project' => (string)($settings['projectKey'] ?? ''),
      'board' => self::selectedFilterLabel('boards', (string)($settings['boardId'] ?? '')),
      'sprint' => self::selectedFilterLabel('sprints', (string)($settings['sprintId'] ?? '')),
      'search' => self::input('filters', 'search')->getValue(),
      'updated' => self::dateSummary('updated'),
      'created' => self::dateSummary('created'),
    ];
    foreach (['status', 'type', 'priority', 'assignee'] as $group) {
      $labels = [];
      foreach (self::list('filters', $group . '-options')->items() as $item) {
        if ($item['selected']) {
          $labels[] = $item['label'];
        }
      }
      $values[$group] = implode(', ', $labels);
    }
    foreach (self::FILTER_TILES as $key) {
      $value = trim((string)($values[$key] ?? ''));
      self::$filterCompact[$key]->instance()->setValue($value !== '' ? $value : '-');
    }
  }

  private static function dateSummary(string $field): string {
    $from = self::$dateSlots[$field]['from']['enabled'] ? self::date('filters', $field . '-date')->getValue() : '';
    $to = self::$dateSlots[$field]['to']['enabled'] ? self::date('filters', $field . '-to-date')->getValue() : '';
    if ($from !== '' && $to !== '') {
      return $from . ' – ' . $to;
    }
    return $from !== '' ? 'From ' . $from : ($to !== '' ? 'To ' . $to : '');
  }

  /** Use the visible choice name in collapsed board and sprint summaries. */
  private static function selectedFilterLabel(string $id, string $value): string {
    if ($value === '') {
      return '';
    }
    foreach (self::list('filters', $id)->items() as $item) {
      if ($item['value'] === $value) {
        return $item['label'];
      }
    }
    return $value;
  }

  /** Show cached choices in each filter tile. */
  private static function renderFilterOptions(): void {
    $settings = Settings::load();
    $project = (string)($settings['projectKey'] ?? '');
    $cached = FilterCache::load($project);
    $scope = $project === '' ? 'global' : 'project';
    $filters = FilterState::load();
    foreach (['status', 'type', 'priority', 'assignee'] as $group) {
      $selected = $filters[$group];
      $available = $group === 'priority' && $cached['priorityScope'] !== $scope ? [] : ($cached[$group] ?? []);
      $values = array_unique(array_merge($available, $selected));
      if ($group === 'assignee') {
        $values = array_unique(array_merge([JqlBuilder::ASSIGNEE_ME, JqlBuilder::ASSIGNEE_UNASSIGNED], $values));
      }
      $items = [];
      foreach ($values as $value) {
        $label = $value;
        if ($value === JqlBuilder::ASSIGNEE_ME) {
          $label = 'Current user';
        } else if ($value === JqlBuilder::ASSIGNEE_UNASSIGNED) {
          $label = 'Unassigned';
        } else if ($group === 'assignee') {
          foreach ($cached['assigneeUsers'] ?? [] as $user) {
            if ($user['accountId'] === $value) {
              $label = $user['displayName'];
              break;
            }
          }
        }
        $items[] = ['value' => $value, 'label' => $label, 'selected' => in_array($value, $selected, true)];
      }
      self::list('filters', $group . '-options')->setItems($items);
    }
    self::refreshFilterSummaries();
  }

  /** Show saved custom JQL filters. */
  private static function renderCustomFilters(): void {
    $filters = FilterState::load();
    $items = array_map(fn(array $row): array => ['value' => $row['name'], 'label' => $row['name']], $filters['customFilters']);
    self::list('filters', 'custom-filters')->setItems($items);
    self::selectKnown('filters', 'custom-filters', self::$editingFilterName ?: $filters['selectedCustomFilter']);
    self::renderSidebarFilters();
  }

  /** Refresh saved filter choices when the quick sidebar is open. */
  private static function renderSidebarFilters(): void {
    $list = self::$window->screen('list')->widget('sidebar-filters');
    if (!$list instanceof ListView) {
      return;
    }
    $filters = FilterState::load();
    $items = [['value' => self::LAST_JQL_ITEM, 'label' => 'Last JQL']];
    foreach ($filters['customFilters'] as $row) {
      $items[] = ['value' => $row['name'], 'label' => $row['name']];
    }
    $list->setItems($items);
    self::selectKnown('list', 'sidebar-filters', $filters['selectedCustomFilter'] ?: self::LAST_JQL_ITEM);
  }

  /** Restore the table to full width after closing quick filters. */
  private static function closeFilterSidebar(): void {
    if (!self::$sidebarOpen) {
      return;
    }
    $screen = self::$window->screen('list');
    if (!$screen->layout->replaceChild(self::$listSidebar, self::$listTableOnly)) {
      throw new \LogicException('List sidebar layout is missing.');
    }
    self::$sidebarOpen = false;
    self::$sidebarSelection = null;
    $focus = self::$listFocusBeforeSidebar;
    self::$listFocusBeforeSidebar = null;
    $screen->setLayout($screen->layout);
    if ($focus !== null) {
      $screen->selectLeaf($focus);
    }
    self::$window->refreshLayout();
  }

  /** Keep every ticket widget visible while giving the selected card more height. */
  private static function prepareTicketTiles(): void {
    $screen = self::$window->screen('ticket');
    $deck = $screen->layout->findNode('ticket-deck');
    foreach (self::TICKET_CARDS as $key) {
      $node = $deck->findNode('ticket-' . $key . '-card');
      $expanded = new LayoutNode('vertical', '1*', '3*', id: 'ticket-open-' . $key);
      $compact = new LayoutNode('vertical', '1*', '1*', id: 'ticket-closed-' . $key);
      $expanded->addNode($node);
      $compact->addNode($node);
      $deck->replaceChild($node, $key === self::$activeTicketCard ? $expanded : $compact);
      self::$ticketExpanded[$key] = $expanded;
      self::$ticketCompact[$key] = $compact;
    }
    foreach (['status' => 'Status', 'type' => 'Type', 'priority' => 'Priority', 'assignee' => 'Assignee', 'reporter' => 'Reporter', 'created' => 'Created', 'updated' => 'Updated', 'labels' => 'Labels'] as $key => $label) {
      $node = $screen->layout->findNode('ticket-property-' . $key);
      $old = $node->leaves()[0];
      $summary = new FilterSummary($label);
      $summary->setId('ticket-' . $key);
      $node->replaceChild($old, new LayoutLeaf('FilterSummary', '1*', '1*', $summary));
      self::$ticketProperties[$key] = $summary;
    }
    self::$ticketPropertiesPane = $screen->layout->findNode('ticket-properties-pane');
    self::$ticketHistoryList = new ListView([], false, false, false, false, new Style(), 'History');
    self::$ticketHistoryList->setId('ticket-history');
    self::$ticketHistoryList->setTips('Up/Down previews tickets; Return opens one; Esc closes History.');
    self::$ticketHistoryLeaf = new LayoutLeaf('List', '1*', '1*', self::$ticketHistoryList, [
      new EventDefinition('change', null, self::class . '::previewTicketHistory'),
      new EventDefinition('keyDown', 'enter', self::class . '::acceptTicketHistory'),
      new EventDefinition('keyDown', 'escape', self::class . '::cancelTicketHistory'),
      new EventDefinition('deactivate', null, self::class . '::cancelTicketHistory'),
    ]);
    self::$ticketHistoryPane = new LayoutNode('vertical', '1*', '1*', id: 'ticket-history-pane');
    self::$ticketHistoryPane->addLeaf(self::$ticketHistoryLeaf);
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** Expand the ticket card reached with an arrow key. */
  public static function expandTicketCard(EventContext $event): void {
    $id = $event->widget?->id() ?? '';
    $key = match ($id) {
      'ticket-description' => 'description',
      'ticket-comments' => 'comments',
      'ticket-attachments' => 'attachments',
      'ticket-related' => 'related',
      default => '',
    };
    if (!isset(self::$ticketExpanded[$key]) || $key === self::$activeTicketCard) {
      return;
    }
    $screen = self::$window->screen('ticket');
    $deck = $screen->layout->findNode('ticket-deck');
    $deck->replaceChild(self::$ticketExpanded[self::$activeTicketCard], self::$ticketCompact[self::$activeTicketCard]);
    $deck->replaceChild(self::$ticketCompact[$key], self::$ticketExpanded[$key]);
    self::$activeTicketCard = $key;
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** Open Description when a ticket loads, regardless of the previous card. */
  private static function resetTicketDeck(): void {
    if (self::$activeTicketCard === 'description') {
      return;
    }
    $screen = self::$window->screen('ticket');
    $deck = $screen->layout->findNode('ticket-deck');
    $deck->replaceChild(self::$ticketExpanded[self::$activeTicketCard], self::$ticketCompact[self::$activeTicketCard]);
    $deck->replaceChild(self::$ticketCompact['description'], self::$ticketExpanded['description']);
    self::$activeTicketCard = 'description';
    $screen->setLayout($screen->layout);
  }

  /** Replace the property cards with the most recently opened tickets. */
  public static function showTicketHistory(EventContext $event): void {
    if (self::$ticketHistoryOpen) {
      self::cancelTicketHistory($event);
      return;
    }
    self::$ticketBeforeHistory = self::$ticket;
    self::$ticketKeyBeforeHistory = self::$ticketKey;
    self::$ticketHistoryRows = [];
    $items = [];
    foreach (TicketHistory::load() as $row) {
      $key = $row['key'];
      self::$ticketHistoryRows[$key] = $row;
      $items[] = ['value' => $key, 'label' => '#' . $key . ($row['title'] === '' ? '' : ' ' . $row['title'])];
    }
    self::$ticketHistoryList->setItems($items);
    if (isset(self::$ticketHistoryRows[self::$ticketKey])) {
      self::$ticketHistoryList->setValue(self::$ticketKey);
    }
    self::resetTicketDeck();
    $screen = self::$window->screen('ticket');
    if (!$screen->layout->replaceChild(self::$ticketPropertiesPane, self::$ticketHistoryPane)) {
      throw new \LogicException('Ticket property pane is missing.');
    }
    self::$ticketHistoryOpen = true;
    $screen->setLayout($screen->layout);
    $screen->activateLeaf(self::$ticketHistoryLeaf);
    self::previewTicketHistory($event);
    self::$window->refreshLayout();
  }

  /** Show cached details for the highlighted history entry without changing history order. */
  public static function previewTicketHistory(EventContext $event): void {
    if (!self::$ticketHistoryOpen) {
      return;
    }
    $key = self::$ticketHistoryList->getValue();
    if (!is_string($key) || $key === '') {
      return;
    }
    $row = self::$ticketHistoryRows[$key] ?? [];
    self::$ticketKey = $key;
    self::$ticket = self::$data->cachedTicket($key) ?? [
      'key' => $key,
      'fields' => ['summary' => (string)($row['title'] ?? '')],
    ];
    self::renderTicket();
    self::$window->refreshLayout();
  }

  /** Commit the highlighted history ticket when Return is pressed. */
  public static function acceptTicketHistory(EventContext $event): bool {
    if (!self::$ticketHistoryOpen) {
      return false;
    }
    $key = self::$ticketHistoryList->getValue();
    self::cancelTicketHistory($event);
    if (is_string($key) && $key !== '') {
      self::openTicket($key);
    }
    return true;
  }

  /** Hide History and restore the ticket that was open before previewing. */
  public static function cancelTicketHistory(EventContext $event): bool {
    if (!self::$ticketHistoryOpen) {
      return false;
    }
    self::$ticketHistoryOpen = false;
    $screen = self::$window->screen('ticket');
    $screen->release('cancel');
    self::$ticket = self::$ticketBeforeHistory;
    self::$ticketKey = self::$ticketKeyBeforeHistory;
    self::renderTicket();
    if (!$screen->layout->replaceChild(self::$ticketHistoryPane, self::$ticketPropertiesPane)) {
      throw new \LogicException('Ticket history pane is missing.');
    }
    $screen->setLayout($screen->layout);
    foreach ($screen->layout->leaves() as $leaf) {
      if ($leaf->instance()->id() === 'ticket-history-button') {
        $screen->selectLeaf($leaf);
        break;
      }
    }
    self::$window->refreshLayout();
    return true;
  }

  /** Show fields, comments, attachments, and related issues of the current ticket. */
  private static function renderTicket(): void {
    $fields = is_array(self::$ticket['fields'] ?? null) ? self::$ticket['fields'] : [];
    self::title('ticket', 'ticket-key')->setText(self::$ticketKey === '' ? 'Ticket' : self::$ticketKey);
    self::input('ticket', 'ticket-summary')->setValue((string)($fields['summary'] ?? ''));
    self::editor('ticket', 'ticket-description')->setValue(Adf::toText($fields['description'] ?? null));
    self::editor('ticket', 'ticket-comments')->setValue(self::$ticketKey === '' ? '' : self::ticketCommentsText());
    $attachments = [];
    foreach ($fields['attachment'] ?? [] as $attachment) {
      if (is_array($attachment)) {
        $attachments[] = [(string)($attachment['author']['displayName'] ?? ''), (string)($attachment['filename'] ?? ''), self::attachmentSize((int)($attachment['size'] ?? 0)) . '  ' . substr((string)($attachment['created'] ?? ''), 0, 10)];
      }
    }
    self::table('ticket', 'ticket-attachments')->setRows(['Author', 'File', 'Size / created'], $attachments);
    self::renderRelatedTickets($fields);
    foreach (['status' => $fields['status']['name'] ?? '', 'type' => $fields['issuetype']['name'] ?? '', 'priority' => $fields['priority']['name'] ?? '', 'assignee' => $fields['assignee']['displayName'] ?? 'Unassigned', 'reporter' => $fields['reporter']['displayName'] ?? '', 'created' => substr((string)($fields['created'] ?? ''), 0, 16), 'updated' => substr((string)($fields['updated'] ?? ''), 0, 16), 'labels' => implode(', ', $fields['labels'] ?? [])] as $key => $value) {
      self::$ticketProperties[$key]->setValue(self::$ticketKey === '' ? '' : ((string)$value !== '' ? (string)$value : '-'));
    }
  }

  /** Format the current issue's comment history for the viewer. */
  private static function ticketCommentsText(): string {
    $comments = [];
    foreach (self::$ticket['fields']['comment']['comments'] ?? [] as $comment) {
      if (!is_array($comment)) {
        continue;
      }
      $author = (string)($comment['author']['displayName'] ?? 'Unknown');
      $date = substr((string)($comment['created'] ?? ''), 0, 16);
      $comments[] = $author . '  ' . $date . "\n" . Adf::toText($comment['body'] ?? null);
    }
    return $comments === [] ? '(no comments)' : implode("\n\n", $comments);
  }

  /** Fill the related-ticket table from the parent, children, and issue links. */
  private static function renderRelatedTickets(array $fields): void {
    $rows = [];
    self::$relatedTicketKeys = [];
    if (is_array($fields['parent'] ?? null)) {
      self::addRelatedTicket($rows, 'Parent', $fields['parent']);
    }
    foreach ($fields['subtasks'] ?? [] as $issue) {
      if (is_array($issue)) {
        self::addRelatedTicket($rows, 'Child', $issue);
      }
    }
    foreach ($fields['issuelinks'] ?? [] as $link) {
      if (!is_array($link)) {
        continue;
      }
      $issue = $link['outwardIssue'] ?? $link['inwardIssue'] ?? null;
      if (is_array($issue)) {
        $relation = isset($link['outwardIssue']) ? ($link['type']['outward'] ?? 'Related') : ($link['type']['inward'] ?? 'Related');
        self::addRelatedTicket($rows, (string)$relation, $issue);
      }
    }
    self::table('ticket', 'ticket-related')->setRows(['Relation', 'Key', 'Summary', 'Status'], $rows);
  }

  /** Add a distinct issue to the related-ticket table. */
  private static function addRelatedTicket(array &$rows, string $relation, array $issue): void {
    $key = (string)($issue['key'] ?? '');
    if ($key === '' || in_array($key, self::$relatedTicketKeys, true)) {
      return;
    }
    $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
    $rows[] = [$relation, $key, (string)($fields['summary'] ?? ''), (string)($fields['status']['name'] ?? '')];
    self::$relatedTicketKeys[] = $key;
  }

  /** Format an attachment size for the table. */
  private static function attachmentSize(int $bytes): string {
    if ($bytes < 1024) {
      return $bytes . ' B';
    }
    if ($bytes < 1048576) {
      return round($bytes / 1024) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
  }

  /** Find an input by XML id. */
  private static function input(string $screen, string $id): Input {
    return self::$window->screen($screen)->widget($id) ?? ($screen === 'filters' ? self::$filterWidgets[$id] : null);
  }

  /** Find a list by XML id. */
  private static function list(string $screen, string $id): ListView {
    return self::$window->screen($screen)->widget($id) ?? ($screen === 'filters' ? self::$filterWidgets[$id] : null);
  }

  /** Find a table by XML id. */
  private static function table(string $screen, string $id): Table {
    return self::$window->screen($screen)->widget($id);
  }

  /** Find a title by XML id. */
  private static function title(string $screen, string $id): Title {
    return self::$window->screen($screen)->widget($id) ?? ($screen === 'filters' ? self::$filterWidgets[$id] : null);
  }

  private static function date(string $screen, string $id): DateSelector {
    return self::$window->screen($screen)->widget($id) ?? ($screen === 'filters' ? self::$filterWidgets[$id] : null);
  }

  /** Find a text widget by XML id. */
  private static function text(string $screen, string $id): Text {
    return self::$window->screen($screen)->widget($id);
  }

  /** Find an editor by XML id. */
  private static function editor(string $screen, string $id): TextEditor {
    return self::$window->screen($screen)->widget($id) ?? ($screen === 'filters' ? self::$filterWidgets[$id] : null);
  }
}
