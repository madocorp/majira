<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Adf;
use MAJIRA\Highlighting\MarkdownHighlighter;
use SPTK\App;
use SPTK\Core\AppData;
use SPTK\Core\Browser;
use SPTK\Core\Clipboard;
use SPTK\Core\Window;
use SPTK\Core\Style;
use SPTK\Core\Widget;
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
  private const SESSION_FILE = 'session.json';
  private const RESTORED_SCREENS = ['list', 'filters', 'board', 'ticket', 'settings'];

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
  private static array $ticketAttachments = [];
  private static array $ticketCommentRanges = [];
  private static ?string $commentEditingId = null;
  private static string $commentOriginalBody = '';
  private static bool $commentSaveRequested = false;
  private static array $commentSaveDocument = [];
  private static ?string $commentCurrentAccountId = null;
  private static ?LayoutLeaf $attachmentPreviewLeaf = null;
  private static ?int $attachmentPreviewRow = null;
  private static ?string $attachmentUploadPath = null;
  /** <@attachment>, <#KEY>, [label](url), <url>, or a bare http(s) URL. */
  private const LINE_LINK = '~<@([^<>\n]+)>|<#([A-Z][A-Z0-9_]*-\d+)>|\[[^\]\n]+\]\(((?:https?://|mailto:)[^\s)]+)\)|<((?:https?://|mailto:)[^\s<>]+)>|(https?://[^\s<>()\[\]]+)~u';
  private const TICKET_CARDS = ['description' => 'Description', 'comments' => 'Comments', 'attachments' => 'Attachments', 'related' => 'Related tickets'];
  private static array $ticketCardSummaryValues = [];
  private static array $ticketProperties = [];
  private static array $ticketPropertyNodes = [];
  private static bool $ticketKeyOpenRequested = false;
  private static ?LayoutNode $ticketPropertyEditorNode = null;
  private static ?LayoutLeaf $ticketPropertyEditorLeaf = null;
  private static ?Widget $ticketPropertyEditorWidget = null;
  private static ?array $ticketPropertyEditModel = null;
  private static mixed $ticketPropertySaveValue = null;
  private static string $editingTicketProperty = '';
  private static bool $ticketPropertySaveRequested = false;
  private static array $ticketEditMeta = [];
  private static string $ticketEditMetaKey = '';
  private static ?ListView $settingsFieldsList = null;
  private static ?array $settingsFieldOptionsDraft = null;
  private static array $settingsSelectedDraft = [];
  private static bool $settingsFieldsOpen = false;
  private static array $relatedTicketKeys = [];
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
  private const FILTER_TILES = ['project', 'board', 'sprint', 'search', 'parent', 'updated', 'created', 'status', 'type', 'priority', 'assignee'];
  private static array $filterSummaryValues = [];
  private static array $filterWidgets = [];
  private static array $dateSlots = [];
  private static string $editingFilterName = '';
  private static string $editingJqlText = '';
  private static bool $showingJqlFilter = false;

  /** Restore cached data after the window opens. */
  public static function initialize(EventContext $event): void {
    self::$window = array_values(App::eventLoop()->windows())[0];
    self::$data = new JiraData();
    Adf::setSite((string)(Settings::load()['site'] ?? ''));
    self::prepareFieldSettings();
    self::prepareAttachmentPreview();
    self::prepareListSidebar();
    self::prepareBoard();
    self::prepareFilterTiles();
    self::prepareTicketTiles();
    $cached = TicketCache::load();
    self::$issues = is_array($cached['issues'] ?? null) ? $cached['issues'] : [];
    self::$meta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
    $settings = Settings::load();
    foreach (['jira-site' => 'site', 'jira-email' => 'email', 'jira-token' => 'apiToken'] as $id => $key) {
      self::input('settings', $id)->setValue((string)($settings[$key] ?? ''));
    }
    self::text('settings', 'settings-unlicense')->setText(trim((string)file_get_contents(APP_DIR . '/UNLICENSE')));
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::renderNavigation();
    self::renderBoardSelectors();
    $boardState = BoardState::load();
    self::$boardAssigneeKey = (string)$boardState['assignee'];
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
    self::restoreScreen();
    Instance::listen();
    self::openTarget(Instance::takeTarget());
  }

  /** Show the window and open the targets sent by later MaJira starts. */
  public static function receiveTargets(EventContext $event): void {
    foreach (Instance::receive() as $target) {
      self::$window->raise();
      self::openTarget($target);
    }
  }

  /** Open a ticket from a key or a Jira ticket URL; an empty target does nothing. */
  private static function openTarget(string $target): void {
    if ($target === '') {
      return;
    }
    $key = preg_match('~^[A-Z][A-Z0-9_]*-\d+$~i', $target) === 1 ? strtoupper($target) : self::ticketKeyFromUrl($target);
    if ($key === null) {
      self::status('Not a Jira ticket key or URL: ' . $target, true);
      return;
    }
    self::openTicket($key);
  }

  /** Open the screen that was visible when the app was closed. */
  private static function restoreScreen(): void {
    $screen = (string)(AppData::loadJson(self::SESSION_FILE)['screen'] ?? '');
    if (!in_array($screen, self::RESTORED_SCREENS, true) || ($screen === 'ticket' && self::$ticketKey === '')) {
      return;
    }
    self::$window->setCurrentScreenId($screen);
    self::$window->refreshLayout();
  }

  /** Remember the visible screen; the attachment preview reopens as its ticket. */
  public static function saveScreen(EventContext $event): void {
    $screen = self::$window->currentScreenId();
    $screen = $screen === 'attachment-preview' ? 'ticket' : $screen;
    if (in_array($screen, self::RESTORED_SCREENS, true)) {
      AppData::saveJson(self::SESSION_FILE, ['screen' => $screen]);
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
      self::setBoardAssignee($entry['key']);
      self::closeBoardAssigneeFilter($event);
      if (self::$boardResult !== null) {
        self::showBoardResult(self::$boardResult, false);
      }
      return;
    }
  }

  /** Change the Board assignee filter and keep it for the next app start. */
  private static function setBoardAssignee(string $key): void {
    self::$boardAssigneeKey = $key;
    $state = BoardState::load();
    if ($state['assignee'] !== $key) {
      $state['assignee'] = $key;
      BoardState::save($state);
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
    self::setBoardAssignee('');
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
      self::setBoardAssignee('');
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
    $estimate = (string)(self::$boardResult['configuration']['estimation']['field']['fieldId'] ?? '');
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
        $badge = BoardTicketCard::badge($issue['fields'][$estimate] ?? null, (string)($issue['fields']['priority']['name'] ?? ''));
        $card = new BoardTicketCard($key, $summary, $assignee, $type, $badge);
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

  /** Find the field selector that settings.xml defines in place of the Settings controls. */
  private static function prepareFieldSettings(): void {
    self::$settingsFieldsList = self::$window->screen('settings')->widget('settings-fields-list');
  }

  /** Load Jira fields and show a selector in place of the Settings controls. */
  public static function openFieldSettings(EventContext $event): void {
    $settings = Settings::load();
    self::$settingsFieldOptionsDraft = null;
    if (Settings::isConfigured($settings)) {
      $catalog = null;
      self::request('GET Jira fields', static function() use (&$catalog): void {
        $catalog = FieldConfiguration::normalizeCatalog(self::$data->client()->fields());
      });
      if (is_array($catalog)) {
        self::$settingsFieldOptionsDraft = $catalog;
        $settings['fieldOptions'] = $catalog;
      }
    }
    self::populateFieldSettings(FieldConfiguration::selected($settings), $settings);
    self::$settingsFieldsOpen = true;
    self::$window->screen('settings')->show('settings-fields-list');
    self::$window->refreshLayout();
  }

  /** Preserve selected order while placing all remaining Jira fields below it. */
  private static function populateFieldSettings(array $selected, array $settings): void {
    $options = FieldConfiguration::options($settings);
    $ordered = [];
    foreach ($selected as $id) {
      if (isset($options[$id])) {
        $ordered[$id] = $options[$id];
      }
    }
    foreach ($options as $id => $name) {
      $ordered[$id] ??= $name;
    }
    $items = [];
    foreach ($ordered as $id => $name) {
      $items[] = ['value' => $id, 'label' => $name . '  [' . $id . ']', 'selected' => in_array($id, $selected, true)];
    }
    self::$settingsFieldsList->setItems($items);
    self::$settingsSelectedDraft = $selected;
  }

  /** Place toggled fields at the selected boundary, preserving their cursor. */
  public static function fieldSelectionChanged(EventContext $event): void {
    if (!self::$settingsFieldsOpen) {
      return;
    }
    $list = self::$settingsFieldsList;
    $current = $list->getValue();
    $changed = $list->activeValue();
    if ($changed === null || in_array($changed, $current, true) === in_array($changed, self::$settingsSelectedDraft, true)) {
      self::$settingsSelectedDraft = $current;
      return;
    }
    $selected = [];
    $available = [];
    $changedItem = null;
    foreach ($list->items() as $item) {
      if ($item['value'] === $changed) {
        $changedItem = $item;
      } else if ($item['selected']) {
        $selected[] = $item;
      } else {
        $available[] = $item;
      }
    }
    if ($changedItem === null) {
      self::$settingsSelectedDraft = $current;
      return;
    }
    if ($changedItem['selected']) {
      $selected[] = $changedItem;
    } else {
      array_unshift($available, $changedItem);
    }
    $list->setItems(array_merge($selected, $available));
    $list->setCursorValue($changed);
    self::$settingsSelectedDraft = $list->getValue();
    self::$window->refreshLayout();
  }

  /** Keep manual reordering inside the selected and available groups. */
  public static function fieldOrderChanged(EventContext $event): void {
    if (!self::$settingsFieldsOpen) {
      return;
    }
    $list = self::$settingsFieldsList;
    $selected = [];
    $available = [];
    $needsGrouping = false;
    foreach ($list->items() as $item) {
      if ($item['selected']) {
        $needsGrouping = $needsGrouping || $available !== [];
        $selected[] = $item;
      } else {
        $available[] = $item;
      }
    }
    if ($needsGrouping) {
      $active = $list->activeValue();
      $list->setItems(array_merge($selected, $available));
      if ($active !== null) {
        $list->setCursorValue($active);
      }
      self::$window->refreshLayout();
    }
    self::$settingsSelectedDraft = $list->getValue();
  }

  /** Persist one field order shared by list columns and ticket cards. */
  public static function saveFieldSettings(EventContext $event): bool {
    if (!self::$settingsFieldsOpen) {
      return false;
    }
    $fields = self::$settingsFieldsList->getValue();
    if ($fields === []) {
      self::status('Select at least one field.', true);
      return true;
    }
    $settings = Settings::load();
    $settings['fields'] = $fields;
    if (self::$settingsFieldOptionsDraft !== null) {
      $settings['fieldOptions'] = self::$settingsFieldOptionsDraft;
    }
    if (!Settings::save($settings)) {
      self::status('Could not save field settings.', true);
      return true;
    }
    self::closeFieldSettings();
    self::configureTicketPropertyTiles();
    self::renderTickets();
    self::renderTicket();
    self::status('Fields saved for List and Ticket.');
    if (Settings::isConfigured($settings)) {
      if (self::$issues !== []) {
        self::request('Refresh List fields', static function(): void {
          $result = self::$data->ticketsPreservingCache((new JqlBuilder())->current());
          self::$issues = $result['issues'];
          self::$meta = $result['meta'];
          self::renderTickets();
        });
      }
      if (self::$ticketKey !== '') {
        self::request('Refresh Ticket fields', static function(): void {
          self::$ticket = self::$data->ticket(self::$ticketKey, true);
          self::renderTicket();
        });
      }
    }
    return true;
  }

  /** Discard the selector draft and restore the connection controls. */
  public static function cancelFieldSettings(EventContext $event): bool {
    if (!self::$settingsFieldsOpen) {
      return false;
    }
    self::closeFieldSettings();
    return true;
  }

  /** Restore the Settings parent tile and remeasure its compact layout. */
  private static function closeFieldSettings(): void {
    self::$settingsFieldsOpen = false;
    self::$settingsFieldOptionsDraft = null;
    self::$settingsSelectedDraft = [];
    $screen = self::$window->screen('settings');
    $screen->restore('settings-fields-list');
    $screen->selectLeaf(self::shownLeaf('settings', 'settings-fields'));
    self::$window->refreshLayout();
  }

  /** Verify and save connection settings, then clear data for the old account. */
  public static function connect(EventContext $event): void {
    $settings = Settings::load();
    $next = [
      'site' => rtrim(trim(self::input('settings', 'jira-site')->getValue()), '/'),
      'email' => trim(self::input('settings', 'jira-email')->getValue()),
      'apiToken' => trim(self::input('settings', 'jira-token')->getValue()),
    ];
    if (!Settings::isConfigured($next) || !preg_match('~^https://[^/]+$~i', $next['site'])) {
      self::status('Enter an HTTPS Jira site, email and API token.', true);
      return;
    }
    $user = null;
    if (!self::request('GET current Jira user', function() use ($next, &$user): void {
      $user = self::$data->verifyConnection($next);
    })) {
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
      $settings['fieldOptions'] = [];
      $settings['fields'] = FieldConfiguration::DEFAULT_FIELDS;
      Settings::save($settings);
      self::$issues = [];
      self::$meta = [];
      self::renderNavigation();
      self::renderTickets();
      self::configureTicketPropertyTiles();
      self::renderTicket();
      self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    }
    if ($changed && !self::reloadProjects($event)) {
      return;
    }
    self::status('Connected as ' . (string)($user['displayName'] ?? $user['emailAddress'] ?? 'Jira user'));
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
    $filters['parent'] = FilterState::parentKey(self::input('filters', 'parent')->getValue());
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

  /** Ignore navigation keys in the search and parent inputs. */
  public static function textFilterChanged(EventContext $event): void {
    $filters = FilterState::load();
    if (trim(self::input('filters', 'search')->getValue()) !== $filters['search']['text']
      || FilterState::parentKey(self::input('filters', 'parent')->getValue()) !== $filters['parent']) {
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
      self::backgroundStatus('This filter is already in JQL mode.');
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
    self::backgroundStatus('JQL mode saved. Recreate this filter to use the builder again.');
  }

  /** Reset the current saved or unnamed filter without changing its identity. */
  public static function clearFilter(EventContext $event): void {
    $filters = FilterState::clearCurrent(FilterState::load());
    Settings::save(array_replace(Settings::load(), FilterState::scopeValues([])));
    FilterState::save($filters);
    self::renderNavigation();
    self::renderFilters();
    self::input('list', 'jql')->setValue('');
    self::backgroundStatus('Filter cleared.');
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
    self::$window->screen('filters')->activateLeaf(self::shownLeaf('filters', 'custom-name'));
    self::backgroundStatus('New filter added.');
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
    self::backgroundStatus(($old === '' ? 'Saved filter: ' : 'Renamed filter: ') . $name);
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
    self::backgroundStatus('Using saved filter: ' . $name);
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
      self::input('list', 'jql')->setValue('');
    }
    self::backgroundStatus('Deleted filter: ' . $name);
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
    if (self::$ticketKey === '' || $description === self::media()->toText(self::$ticket['fields']['description'] ?? null)) {
      return;
    }
    $document = null;
    if (self::request('Check attachments in description', static function() use ($description, &$document): void {
      $document = self::media()->fromMarkdown($description);
    })) {
      self::writeTicket(['description' => $document], 'PUT description');
    }
  }

  /** Discard description edits before Escape releases the editor. */
  public static function cancelDescription(EventContext $event): void {
    self::editor('ticket', 'ticket-description')->setValue(self::media()->toText(self::$ticket['fields']['description'] ?? null));
  }

  /** Open an editor in the selected property tile when Jira permits the field. */
  public static function editTicketProperty(EventContext $event): bool {
    $widget = $event->widget;
    if (!$widget instanceof FilterSummary || !str_starts_with((string)$widget->id(), 'ticket-')) {
      return false;
    }
    $field = substr((string)$widget->id(), 7);
    if (!isset(self::$ticketPropertyNodes[$field])) {
      return false;
    }
    if (self::$ticketKey === '') {
      self::status('Open a ticket before editing a property.', true);
      return true;
    }
    if (in_array($field, ['key', 'created', 'updated'], true)) {
      self::status(FieldConfiguration::label($field) . ' is managed by Jira and cannot be edited.', true);
      return true;
    }
    $jiraField = FieldConfiguration::jiraId($field);
    $model = null;
    if ($field === 'status') {
      $transitions = [];
      if (!self::request('GET Jira transitions ' . self::$ticketKey, static function() use (&$transitions): void {
        $transitions = self::$data->client()->transitions(self::$ticketKey)['transitions'] ?? [];
      })) {
        return true;
      }
      $options = [];
      foreach ($transitions as $transition) {
        if (is_array($transition) && (string)($transition['id'] ?? '') !== '') {
          $options[] = ['value' => (string)$transition['id'], 'text' => (string)($transition['name'] ?? $transition['to']['name'] ?? $transition['id'])];
        }
      }
      if ($options !== []) {
        $model = ['kind' => 'transition', 'name' => 'Status', 'jiraField' => 'status', 'options' => $options, 'value' => $options[0]['value'], 'required' => true];
      }
    } else {
      if (self::$ticketEditMetaKey !== self::$ticketKey) {
        $metadata = [];
        if (!self::request('GET Jira edit metadata ' . self::$ticketKey, static function() use (&$metadata): void {
          $metadata = self::$data->client()->editMeta(self::$ticketKey);
        })) {
          return true;
        }
        self::$ticketEditMeta = is_array($metadata['fields'] ?? null) ? $metadata['fields'] : [];
        self::$ticketEditMetaKey = self::$ticketKey;
      }
      $meta = self::$ticketEditMeta[$jiraField] ?? null;
      if (!is_array($meta) || TicketAgileFields::isSprint($meta)) {
        $model = self::agilePropertyModel($field, $jiraField, is_array($meta));
        if ($model === false) {
          return true;
        }
      } else {
        $meta = self::propertyUserChoices($jiraField, $meta);
        if ($meta === null) {
          return true;
        }
        $model = (new TicketFieldEditModel())->build(self::$ticket, $field, $jiraField, $meta, self::$ticketProperties[$field]->title());
      }
    }
    if (!is_array($model)) {
      self::status('This property is not editable with the available Jira options.', true);
      return true;
    }
    self::showTicketPropertyEditor($field, $model);
    return true;
  }

  /** Build a Sprint or board estimate editor; false means a Jira request failed. */
  private static function agilePropertyModel(string $field, string $jiraField, bool $sprint): array|false|null {
    $project = (string)(self::$ticket['fields']['project']['key'] ?? strtok(self::$ticketKey, '-'));
    $name = self::$ticketProperties[$field]->title();
    $model = null;
    $loaded = self::request('GET Jira board data for ' . self::$ticketKey, static function() use ($project, $name, $jiraField, $sprint, &$model): void {
      $agile = new TicketAgileFields(self::$data);
      $board = $agile->boardId($project);
      if ($board === '') {
        return;
      }
      $model = $sprint
        ? $agile->sprintModel(self::$ticket, $jiraField, $name, $board)
        : $agile->estimateModel(self::$ticket, $jiraField, $name, $board);
    });
    return $loaded ? $model : false;
  }

  /** Fill user fields when edit metadata omits its selectable accounts. */
  private static function propertyUserChoices(string $jiraField, array $meta): ?array {
    $schema = is_array($meta['schema'] ?? null) ? $meta['schema'] : [];
    if (!empty($meta['allowedValues']) || ($schema['type'] ?? '') !== 'user') {
      return $meta;
    }
    $users = [];
    if (!self::request('GET Jira users ' . self::$ticketKey, static function() use ($jiraField, &$users): void {
      $users = $jiraField === 'assignee'
        ? self::$data->client()->assignableUsersForIssue(self::$ticketKey)
        : self::$data->client()->users();
    })) {
      return null;
    }
    $current = self::$ticket['fields'][$jiraField] ?? null;
    if (is_array($current) && (string)($current['accountId'] ?? '') !== '') {
      $users[] = $current;
    }
    $deduplicated = [];
    foreach ($users as $user) {
      if (is_array($user) && (string)($user['accountId'] ?? '') !== '') {
        $deduplicated[(string)$user['accountId']] = $user;
      }
    }
    uasort($deduplicated, static fn(array $a, array $b): int => strcasecmp((string)($a['displayName'] ?? ''), (string)($b['displayName'] ?? '')));
    $meta['allowedValues'] = array_values($deduplicated);
    return $meta;
  }

  /** Replace the two-row summary with an Input, List, or calendar in the same card. */
  private static function showTicketPropertyEditor(string $field, array $model): void {
    $kind = (string)$model['kind'];
    $name = (string)$model['name'];
    $value = $model['value'] ?? '';
    $height = 2;
    if (in_array($kind, ['choice', 'transition', 'sprint', 'multi-choice', 'boolean'], true)) {
      $items = [];
      $options = $kind === 'boolean'
        ? [['value' => '1', 'text' => 'Yes'], ['value' => '0', 'text' => 'No']]
        : (array)($model['options'] ?? []);
      foreach ($options as $option) {
        $items[] = ['value' => (string)$option['value'], 'label' => (string)($option['text'] ?? $option['value'])];
      }
      $widget = new ListView($items, $kind === 'multi-choice', true, true, false, new Style(), $name);
      $selected = $kind === 'boolean' ? ($value ? '1' : '0') : $value;
      if ($selected !== '' && $selected !== [] && in_array($selected, $widget->values(), true)) {
        $widget->setValue($selected);
      } else if ($kind === 'multi-choice') {
        $widget->setValue(is_array($selected) ? $selected : []);
      }
      $height = max(4, min(11, count($items) + 1));
    } else if ($kind === 'date') {
      $widget = new DateSelector((string)$value, new Style(), $name);
      $height = 10;
    } else if ($kind === 'doc') {
      $widget = new TextEditor((string)$value, new Style(), true, title: $name);
      $widget->setHighlighter(new MarkdownHighlighter());
      $height = 8;
    } else {
      $widget = new Input((string)$value, new Style(), label: $name);
    }
    $widget->setId('ticket-property-editor');
    $saveKey = $kind === 'doc' ? 'ctrl+enter' : 'enter';
    $widget->setTips('Esc cancels; ' . ($kind === 'doc' ? 'Ctrl+Return' : 'Return') . ' saves'
      . ($kind === 'date' && empty($model['required']) ? '; Ctrl+Delete clears.' : '.'));
    self::$ticketPropertyEditorWidget = $widget;
    self::$ticketPropertyEditModel = $model;
    self::$editingTicketProperty = $field;
    self::$ticketPropertySaveRequested = false;
    self::$ticketPropertyEditorLeaf = new LayoutLeaf($kind === 'date' ? 'DateSelector' : ($kind === 'doc' ? 'TextEditor' : ($widget instanceof ListView ? 'List' : 'Input')), '1*', '1*', $widget, [
      new EventDefinition('keyDown', $saveKey, self::class . '::requestTicketPropertySave'),
      new EventDefinition('keyDown', 'ctrl+delete', self::class . '::clearTicketProperty'),
      new EventDefinition('deactivate', null, self::class . '::finishTicketPropertyEdit'),
    ]);
    self::$ticketPropertyEditorNode = new LayoutNode('vertical', '1*', (string)$height, id: 'ticket-property-edit-' . $field);
    self::$ticketPropertyEditorNode->addLeaf(self::$ticketPropertyEditorLeaf);
    $screen = self::$window->screen('ticket');
    $screen->layout->findNode('ticket-properties')->replaceChild(self::$ticketPropertyNodes[$field], self::$ticketPropertyEditorNode);
    $screen->setLayout($screen->layout);
    $screen->activateLeaf(self::$ticketPropertyEditorLeaf);
    self::$window->refreshLayout();
    self::scrollTicketPropertyIntoView();
  }

  /** Keep a resized property editor visible inside the right pane. */
  private static function scrollTicketPropertyIntoView(): void {
    $pane = self::$window->screen('ticket')->layout->findNode('ticket-properties-pane');
    $editor = self::$ticketPropertyEditorNode?->grid();
    $viewport = $pane?->grid();
    if ($editor === null || $viewport === null) {
      return;
    }
    $bottom = $editor->y + min($editor->height, $viewport->height);
    if ($bottom > $viewport->y + $viewport->height) {
      $pane->setScrollOffset($pane->scrollOffset() + $bottom - $viewport->y - $viewport->height);
      self::$window->refreshLayout();
    }
  }

  /** Validate the editor value before Return releases its widget. */
  public static function requestTicketPropertySave(EventContext $event): bool {
    if (self::$ticketPropertyEditModel === null || self::$ticketPropertyEditorWidget === null) {
      return false;
    }
    $widget = self::$ticketPropertyEditorWidget;
    $kind = (string)self::$ticketPropertyEditModel['kind'];
    if ($widget instanceof DateSelector && $widget->dateText() !== $widget->getValue()) {
      self::status('Enter a valid date before saving.', true);
      return true;
    }
    $value = match (true) {
      $widget instanceof Input, $widget instanceof TextEditor, $widget instanceof DateSelector => $widget->getValue(),
      $widget instanceof ListView => $widget->getValue(),
      default => null,
    };
    if ($kind === 'transition') {
      self::$ticketPropertySaveValue = (string)$value;
    } else {
      try {
        self::$ticketPropertySaveValue = in_array($kind, ['sprint', 'estimate'], true)
          ? (new TicketAgileFields(self::$data))->saveValue(self::$ticketPropertyEditModel, $value)
          : (new TicketFieldEditModel())->saveValue(self::$ticketPropertyEditModel, $value);
      } catch (\Throwable $error) {
        self::status($error->getMessage(), true);
        return true;
      }
    }
    self::$ticketPropertySaveRequested = true;
    return false;
  }

  /** Clear an optional date from its calendar tile. */
  public static function clearTicketProperty(EventContext $event): bool {
    if (self::$ticketPropertyEditModel === null || self::$ticketPropertyEditModel['kind'] !== 'date') {
      return false;
    }
    if (!empty(self::$ticketPropertyEditModel['required'])) {
      self::status('This property requires a date.', true);
      return true;
    }
    self::$ticketPropertySaveValue = null;
    self::$ticketPropertySaveRequested = true;
    self::$window->screen('ticket')->release();
    return true;
  }

  /** Restore the summary and write the accepted field or workflow transition. */
  public static function finishTicketPropertyEdit(EventContext $event): void {
    if (self::$editingTicketProperty === '') {
      return;
    }
    $field = self::$editingTicketProperty;
    $model = self::$ticketPropertyEditModel;
    $value = self::$ticketPropertySaveValue;
    $save = self::$ticketPropertySaveRequested;
    self::$editingTicketProperty = '';
    self::$ticketPropertyEditModel = null;
    self::$ticketPropertySaveRequested = false;
    $screen = self::$window->screen('ticket');
    $screen->layout->findNode('ticket-properties')->replaceChild(self::$ticketPropertyEditorNode, self::$ticketPropertyNodes[$field]);
    $screen->setLayout($screen->layout);
    $screen->selectLeaf(self::$ticketPropertyNodes[$field]->leaves()[0]);
    self::$window->refreshLayout();
    if (!$save) {
      return;
    }
    $jiraField = (string)$model['jiraField'];
    $saved = self::request('Update ' . $jiraField . ' on ' . self::$ticketKey, static function() use ($model, $jiraField, $value): void {
      if ($model['kind'] === 'transition') {
        self::$data->client()->transitionIssue(self::$ticketKey, (string)$value);
      } else if (in_array($model['kind'], ['sprint', 'estimate'], true)) {
        (new TicketAgileFields(self::$data))->save(self::$ticketKey, $model, $value);
      } else {
        self::$data->client()->updateIssueFields(self::$ticketKey, [$jiraField => $value]);
      }
      self::$ticket = self::$data->ticket(self::$ticketKey, true);
      self::renderTicket();
    });
    if ($saved) {
      self::$ticketEditMetaKey = '';
    }
  }

  /** Open the comment under the read-only cursor when it belongs to this account. */
  public static function editSelectedComment(EventContext $event): bool {
    if (!self::commentViewerSelected($event) || self::$window->screen('ticket')->activeLeaf()?->instance() !== $event->widget) {
      return false;
    }
    $comment = self::selectedComment($event);
    if ($comment === null) {
      return true;
    }
    if (!self::canModifyComment($comment)) {
      return true;
    }
    self::openCommentEditor((string)$comment['id'], self::media()->toText($comment['body'] ?? null));
    return true;
  }

  /** Open a blank comment draft in the same ticket card. */
  public static function newComment(EventContext $event): bool {
    if (!self::commentViewerSelected($event)) {
      return false;
    }
    if (self::$ticketKey === '') {
      self::status('Open a ticket before adding a comment.', true);
      return true;
    }
    self::openCommentEditor('', '');
    return true;
  }

  /** Ask before deleting the selected comment. */
  public static function deleteSelectedComment(EventContext $event): bool {
    $comment = self::selectedComment($event);
    if ($comment === null) {
      return false;
    }
    if (!self::canModifyComment($comment)) {
      return true;
    }
    $id = (string)$comment['id'];
    $key = self::$ticketKey;
    $author = (string)($comment['author']['displayName'] ?? 'Unknown');
    self::$window->screen('ticket')->statusBar->confirm('Delete comment by ' . $author . '?', static function() use ($key, $id): void {
      if (self::$ticketKey !== $key) {
        return;
      }
      self::writeComment('DELETE comment', static function() use ($key, $id): void {
        self::$data->client()->deleteComment($key, $id);
      });
    }, static fn(): bool => false);
    return true;
  }

  /** Ask before deleting the attachment under the table cursor; Jira checks the permission. */
  public static function deleteSelectedAttachment(EventContext $event): bool {
    if (!$event->widget instanceof Table || $event->widget->id() !== 'ticket-attachments' || self::$ticketKey === '') {
      return false;
    }
    $attachment = self::$ticketAttachments[$event->widget->cursorRow()] ?? null;
    if ($attachment === null) {
      return true;
    }
    $id = (string)($attachment['id'] ?? '');
    $key = self::$ticketKey;
    $name = (string)($attachment['filename'] ?? 'attachment');
    self::$window->screen('ticket')->statusBar->confirm('Delete attachment ' . $name . '?', static function() use ($key, $id, $name): void {
      if (self::$ticketKey !== $key) {
        return;
      }
      self::request('DELETE attachment ' . $name, static function() use ($key, $id): void {
        self::$data->client()->deleteAttachment($id);
        self::$ticket = self::$data->ticket($key, true);
        self::renderTicket();
      });
    }, static fn(): bool => false);
    return true;
  }

  /** Replace the attachment table with a file browser for a new upload. */
  public static function newAttachment(EventContext $event): bool {
    if (!$event->widget instanceof Table || $event->widget->id() !== 'ticket-attachments' || self::$ticketKey === '') {
      return false;
    }
    $screen = self::$window->screen('ticket');
    $screen->widget('ticket-attachment-file')->reload();
    self::$attachmentUploadPath = null;
    $screen->show('ticket-attachment-file');
    self::$window->refreshLayout();
    return true;
  }

  /** Mark a file for upload; directories are left to the browser. */
  public static function acceptAttachmentFile(EventContext $event): bool {
    $path = $event->widget->activeValue();
    if ($path === null || !is_file($path)) {
      return false;
    }
    self::$attachmentUploadPath = $path;
    self::$window->screen('ticket')->release();
    return true;
  }

  /** Upload the chosen file, if any, after the attachment table is back. */
  public static function finishAttachmentSelect(EventContext $event): void {
    self::$window->refreshLayout();
    $path = self::$attachmentUploadPath;
    self::$attachmentUploadPath = null;
    if ($path === null) {
      return;
    }
    $key = self::$ticketKey;
    self::request('Upload ' . basename($path) . ' to ' . $key, static function() use ($key, $path): void {
      self::$data->client()->addAttachment($key, $path);
      self::$ticket = self::$data->ticket($key, true);
      self::renderTicket();
    });
  }

  /** Open the link on the comment line under the read cursor. */
  public static function openCommentLink(EventContext $event): bool {
    if (!self::commentViewerSelected($event) || self::$window->screen('ticket')->activeLeaf()?->instance() !== $event->widget) {
      return false;
    }
    [$row, $column] = $event->widget->cursorPosition();
    self::openLineLink(explode("\n", $event->widget->text())[$row] ?? '', $column);
    return true;
  }

  /** Open the link on the description line under the cursor. */
  public static function openDescriptionLink(EventContext $event): bool {
    if (!$event->widget instanceof TextEditor || $event->widget->id() !== 'ticket-description' || self::$ticketKey === '') {
      return false;
    }
    [$row, $column] = $event->widget->cursorPosition();
    self::openLineLink(explode("\n", $event->widget->getValue())[$row] ?? '', $column);
    return true;
  }

  /** Open the link under the cursor, or the first link of the line. */
  private static function openLineLink(string $line, int $column): void {
    preg_match_all(self::LINE_LINK, $line, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
    if ($links === []) {
      self::status('No link on this line.', true);
      return;
    }
    preg_match_all('/\X/u', $line, $graphemes);
    $offset = strlen(implode('', array_slice($graphemes[0], 0, $column)));
    $link = $links[0];
    foreach ($links as $candidate) {
      if ($offset >= $candidate[0][1] && $offset < $candidate[0][1] + strlen($candidate[0][0])) {
        $link = $candidate;
        break;
      }
    }
    if (($link[1][0] ?? null) !== null) {
      self::openLinkedAttachment($link[0][0]);
      return;
    }
    // A bare URL usually ends before the sentence punctuation.
    $url = $link[3][0] ?? $link[4][0] ?? rtrim((string)($link[5][0] ?? ''), '.,;:!?');
    $key = $link[2][0] ?? self::ticketKeyFromUrl($url);
    if ($key !== null) {
      self::openTicket($key);
      return;
    }
    try {
      Browser::open($url);
      self::backgroundStatus('Opened ' . $url);
    } catch (\RuntimeException $exception) {
      self::status($exception->getMessage(), true);
    }
  }

  /** Return the ticket key of a Jira browse URL on the configured site. */
  private static function ticketKeyFromUrl(string $url): ?string {
    $site = rtrim((string)(Settings::load()['site'] ?? ''), '/');
    $pattern = '~^' . preg_quote($site, '~') . '/browse/([A-Z][A-Z0-9_]*-\d+)(?:[/?#].*)?$~';
    return $site !== '' && preg_match($pattern, $url, $match) === 1 ? $match[1] : null;
  }

  /** Open the preview for an attachment token, or explain why not. */
  private static function openLinkedAttachment(string $line): void {
    $attachment = self::media()->linked($line);
    $ids = array_map(static fn(array $item): string => (string)($item['id'] ?? ''), self::$ticketAttachments);
    $row = $attachment === null ? false : array_search((string)$attachment['id'], $ids, true);
    if ($row === false) {
      self::status('No attachment on this line.', true);
      return;
    }
    self::showAttachmentPreview($row);
  }

  /** Mark Ctrl+Return as a save and keep empty drafts open. */
  public static function requestCommentSave(EventContext $event): bool {
    $body = self::editor('ticket', 'ticket-comment-editor')->getValue();
    if (trim($body) === '') {
      self::status('Comment cannot be empty.', true);
      return true;
    }
    if (!self::request('Check attachments in comment', static function() use ($body): void {
      self::$commentSaveDocument = self::media()->fromMarkdown($body);
    })) {
      return true;
    }
    self::$commentSaveRequested = true;
    return false;
  }

  /** Return to the read-only viewer after Escape or a screen change. */
  public static function finishCommentEdit(EventContext $event): void {
    if (self::$commentEditingId === null) {
      return;
    }
    $id = self::$commentEditingId;
    $body = self::editor('ticket', 'ticket-comment-editor')->getValue();
    $save = self::$commentSaveRequested && ($id === '' || $body !== self::$commentOriginalBody);
    self::$commentEditingId = null;
    self::$commentOriginalBody = '';
    self::$commentSaveRequested = false;
    self::$window->screen('ticket')->activateLeaf(self::shownLeaf('ticket', 'ticket-comments'));
    if (!$save) {
      return;
    }
    $key = self::$ticketKey;
    $document = self::$commentSaveDocument;
    self::writeComment($id === '' ? 'POST comment' : 'PUT comment', static function() use ($key, $id, $document): void {
      if ($id === '') {
        self::$data->client()->addComment($key, $document);
      } else {
        self::$data->client()->updateComment($key, $id, $document);
      }
    });
  }

  /** Ignore Escape so release discards the current comment draft. */
  public static function cancelCommentEdit(EventContext $event): void {
    self::$commentSaveRequested = false;
  }

  /** Locate the comment whose source lines contain the viewer cursor. */
  private static function selectedComment(EventContext $event): ?array {
    if (!self::commentViewerSelected($event)) {
      return null;
    }
    [$line] = self::text('ticket', 'ticket-comments')->cursorPosition();
    foreach (self::$ticketCommentRanges as [$start, $end, $comment]) {
      if ($line >= $start && $line < $end) {
        return $comment;
      }
    }
    self::status('No comment under the cursor.', true);
    return null;
  }

  /** Apply comment shortcuts only while the read-only comment tile is selected. */
  private static function commentViewerSelected(EventContext $event): bool {
    return self::$commentEditingId === null && self::$ticketKey !== ''
      && $event->widget instanceof Text && $event->widget->id() === 'ticket-comments';
  }

  /** Check Jira authorship before opening an edit or delete action. */
  private static function canModifyComment(array $comment): bool {
    $id = (string)($comment['id'] ?? '');
    $author = (string)($comment['author']['accountId'] ?? '');
    if ($id === '' || $author === '') {
      self::status('This comment cannot be modified.', true);
      return false;
    }
    if (self::$commentCurrentAccountId === null) {
      try {
        self::$commentCurrentAccountId = (string)(self::$data->client()->myself()['accountId'] ?? '');
      } catch (\Throwable $error) {
        self::status('Check comment ownership failed: ' . $error->getMessage(), true);
        return false;
      }
    }
    if (self::$commentCurrentAccountId === '' || $author !== self::$commentCurrentAccountId) {
      self::status('Only your own comments can be modified.', true);
      return false;
    }
    return true;
  }

  /** Swap the comment history for a single editable comment body. */
  private static function openCommentEditor(string $id, string $body): void {
    $screen = self::$window->screen('ticket');
    $screen->release();
    self::$commentEditingId = $id;
    self::$commentOriginalBody = $body;
    self::$commentSaveRequested = false;
    $editor = self::editor('ticket', 'ticket-comment-editor');
    $editor->setTitle($id === '' ? 'New comment' : 'Edit comment');
    $editor->setValue($body);
    $screen->show('ticket-comment-editor');
    self::$window->refreshLayout();
  }

  /** Run a comment mutation and refresh the same ticket card after success. */
  private static function writeComment(string $label, callable $operation): void {
    self::request($label . ' ' . self::$ticketKey, static function() use ($operation): void {
      $operation();
      self::$ticket = self::$data->ticket(self::$ticketKey, true);
      self::renderTicket();
    });
  }

  /** Load child issues once, when Return enters the related-ticket card. */
  public static function loadRelatedChildren(EventContext $event): void {
    if (self::$ticketKey === '' || self::$data->cachedChildren(self::$ticketKey) !== null) {
      return;
    }
    self::request('GET children of ' . self::$ticketKey, static function(): void {
      self::$data->children(self::$ticketKey);
      $fields = is_array(self::$ticket['fields'] ?? null) ? self::$ticket['fields'] : [];
      self::renderRelatedTickets($fields);
      self::renderTicketCardSummaries($fields);
    });
  }

  /** Turn the ticket key title into an input where another key can be typed. */
  public static function editTicketKey(EventContext $event): bool {
    if (!$event->widget instanceof Title || $event->widget->id() !== 'ticket-key') {
      return false;
    }
    self::input('ticket', 'ticket-key-editor')->setValue(self::$ticketKey);
    self::$ticketKeyOpenRequested = false;
    self::$window->screen('ticket')->show('ticket-key-editor');
    self::$window->refreshLayout();
    return true;
  }

  /** Copy the Jira browser URL of the current ticket from the key title. */
  public static function copyTicketUrl(EventContext $event): bool {
    if (!$event->widget instanceof Title || $event->widget->id() !== 'ticket-key' || self::$ticketKey === '') {
      return false;
    }
    $url = rtrim((string)(Settings::load()['site'] ?? ''), '/') . '/browse/' . self::$ticketKey;
    Clipboard::set($url);
    self::backgroundStatus('Copied ' . $url);
    return true;
  }

  /** Mark that Return, not Esc, released the ticket key input. */
  public static function requestTicketKeyOpen(EventContext $event): bool {
    self::$ticketKeyOpenRequested = true;
    return false;
  }

  /** Open the typed ticket after Return, once the key title is back. */
  public static function finishTicketKeyEdit(EventContext $event): void {
    $key = strtoupper(trim($event->widget->getValue()));
    self::$window->refreshLayout();
    if (!self::$ticketKeyOpenRequested || $key === '' || $key === self::$ticketKey) {
      return;
    }
    if (!preg_match('/^[A-Z][A-Z0-9_]*-\d+$/', $key)) {
      self::status($key . ' is not a valid ticket key.', true);
      return;
    }
    self::openTicket($key);
  }

  /** Open the related issue selected in the ticket card. */
  public static function openRelatedTicket(EventContext $event): void {
    $key = self::$relatedTicketKeys[self::table('ticket', 'ticket-related')->cursorRow()] ?? '';
    if ($key !== '') {
      self::openTicket($key);
    }
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
        self::$data->forgetChildren($key);
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
    self::$commentCurrentAccountId = null;
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
    $selected = FieldConfiguration::selected();
    $header = array_map(FieldConfiguration::label(...), $selected);
    $rows = [];
    foreach (self::$issues as $issue) {
      $rows[] = array_map(static fn(string $id): string => FieldConfiguration::value($issue, $id), $selected);
    }
    $table->setRows($header, $rows);
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
    self::input('filters', 'parent')->setValue($filters['parent']);
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
    $jql ? $screen->show('filter-jql-mode', false) : $screen->restore('filter-jql-mode');
    self::$showingJqlFilter = $jql;
    self::$window->refreshLayout();
  }

  /** Put an empty placeholder in each date slot; the deck itself comes from filters.xml. */
  private static function prepareFilterTiles(): void {
    $screen = self::$window->screen('filters');
    foreach (['updated', 'created'] as $field) {
      foreach (['from' => $field . '-date', 'to' => $field . '-to-date'] as $bound => $id) {
        $slot = $screen->layout->findNode($field . '-' . $bound . '-slot');
        $date = $slot->leaves()[0];
        // Disabled calendars are outside the layout, so keep them reachable by id.
        self::$filterWidgets[$id] = $date->instance();
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

  /** Paint a closed filter tile as its name and current choice. */
  public static function filterSummary(?string $id): string {
    $key = substr((string)$id, strlen('filter-'));
    return ucfirst($key) . "\n" . (self::$filterSummaryValues[$key] ?? '-');
  }

  /** Update the summaries when a filter tile closes, so it shows the latest choice. */
  public static function filterTileClosed(EventContext $event): void {
    self::refreshFilterSummaries();
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

  /** Select the open filter tile, or the JQL editor, while leaving it ready for Return. */
  private static function focusExpandedFilter(): bool {
    $screen = self::$window->screen('filters');
    if (self::$showingJqlFilter) {
      return $screen->selectLeaf(self::shownLeaf('filters', 'filter-jql'));
    }
    foreach (self::FILTER_TILES as $key) {
      $tile = $screen->layout->findNode('filter-' . $key);
      if ($tile->isDeckOpen()) {
        $screen->selectLeaf($tile->leaves()[0]);
        return true;
      }
    }
    return false;
  }

  /** Find the shown leaf of a widget by XML id. */
  private static function shownLeaf(string $screen, string $id): LayoutLeaf {
    foreach (self::$window->screen($screen)->layout->leaves() as $leaf) {
      if ($leaf->instance()->id() === $id) {
        return $leaf;
      }
    }
    throw new \LogicException('Widget is not shown: ' . $id);
  }

  /** Cache each filter's current choice for its closed deck tile. */
  private static function refreshFilterSummaries(): void {
    if (self::$dateSlots === []) {
      return;
    }
    $settings = Settings::load();
    $values = [
      'project' => (string)($settings['projectKey'] ?? ''),
      'board' => self::selectedFilterLabel('boards', (string)($settings['boardId'] ?? '')),
      'sprint' => self::selectedFilterLabel('sprints', (string)($settings['sprintId'] ?? '')),
      'search' => self::input('filters', 'search')->getValue(),
      'parent' => FilterState::parentKey(self::input('filters', 'parent')->getValue()),
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
      self::$filterSummaryValues[$key] = $value !== '' ? $value : '-';
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
    $markdown = new MarkdownHighlighter();
    self::editor('ticket', 'ticket-description')->setHighlighter($markdown);
    self::text('ticket', 'ticket-comments')->setHighlighter($markdown);
    self::editor('ticket', 'ticket-comment-editor')->setHighlighter($markdown);
    $screen->widget('ticket-attachment-file')->setPath((string)(getenv('HOME') ?: '.'));
    self::configureTicketPropertyTiles();
    $screen->setLayout($screen->layout);
    self::$window->refreshLayout();
  }

  /** Build property cards in the order shared with the ticket list. */
  private static function configureTicketPropertyTiles(): void {
    $pane = self::$window->screen('ticket')->layout->findNode('ticket-properties-pane');
    $old = $pane->findNode('ticket-properties');
    $properties = new LayoutNode('vertical', '1*', 'auto', id: 'ticket-properties');
    self::$ticketProperties = [];
    self::$ticketPropertyNodes = [];
    foreach (FieldConfiguration::selected() as $id) {
      if (in_array($id, ['key', 'summary'], true)) {
        continue;
      }
      if (self::$ticketPropertyNodes !== []) {
        $properties->addSeparator(new LayoutSeparator());
      }
      $node = new LayoutNode('vertical', '1*', '2', id: 'ticket-property-' . $id);
      $summary = new FilterSummary(FieldConfiguration::label($id));
      $summary->setId('ticket-' . $id);
      $node->addLeaf(new LayoutLeaf('FilterSummary', '1*', '1*', $summary));
      $properties->addNode($node);
      self::$ticketProperties[$id] = $summary;
      self::$ticketPropertyNodes[$id] = $node;
    }
    $pane->replaceChild($old, $properties);
    self::$window->screen('ticket')->setLayout(self::$window->screen('ticket')->layout);
  }

  /** Paint a closed ticket card as its title and a one-line summary. */
  public static function ticketCardSummary(?string $id): string {
    $key = preg_replace('/^ticket-(.*)-card$/', '$1', (string)$id);
    return (self::TICKET_CARDS[$key] ?? '') . "\n" . (self::$ticketCardSummaryValues[$key] ?? '');
  }

  /** Name embedded files once Description or Comments opens. */
  public static function ticketCardOpened(EventContext $event): void {
    self::resolveTicketMedia();
  }

  /** Open Description when a ticket loads, regardless of the previous card. */
  private static function resetTicketDeck(): void {
    self::$window->screen('ticket')->openDeckTile('ticket-description-card');
  }

  /** Return the key of the open ticket card. */
  private static function openTicketCard(): string {
    $layout = self::$window->screen('ticket')->layout;
    foreach (array_keys(self::TICKET_CARDS) as $key) {
      if ($layout->findNode('ticket-' . $key . '-card')->isDeckOpen()) {
        return $key;
      }
    }
    return 'description';
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
    $list = self::list('ticket', 'ticket-history');
    $list->setItems($items);
    if (isset(self::$ticketHistoryRows[self::$ticketKey])) {
      $list->setValue(self::$ticketKey);
    }
    self::resetTicketDeck();
    self::$ticketHistoryOpen = true;
    self::$window->screen('ticket')->show('ticket-history');
    self::previewTicketHistory($event);
    self::$window->refreshLayout();
  }

  /** Show cached details for the highlighted history entry without changing history order. */
  public static function previewTicketHistory(EventContext $event): void {
    if (!self::$ticketHistoryOpen) {
      return;
    }
    $key = self::list('ticket', 'ticket-history')->getValue();
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
    $key = self::list('ticket', 'ticket-history')->getValue();
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
    // Releasing the list puts the property cards back; restore covers a list that is not active.
    $screen->release('cancel');
    $screen->restore('ticket-history');
    self::$ticket = self::$ticketBeforeHistory;
    self::$ticketKey = self::$ticketKeyBeforeHistory;
    self::renderTicket();
    $screen->selectLeaf(self::shownLeaf('ticket', 'ticket-history-button'));
    self::$window->refreshLayout();
    return true;
  }

  /** Fill the one-line summaries shown on closed ticket cards. */
  private static function renderTicketCardSummaries(array $fields): void {
    $description = trim(self::media()->toText($fields['description'] ?? null));
    $lines = $description === '' ? 0 : substr_count($description, "\n") + 1;
    self::$ticketCardSummaryValues['description'] = $lines === 0 ? 'No description' : self::plural($lines, 'line');
    $comments = array_filter($fields['comment']['comments'] ?? [], 'is_array');
    $total = (int)($fields['comment']['total'] ?? count($comments));
    $authors = array_values(array_unique(array_map(fn(array $comment): string => (string)($comment['author']['displayName'] ?? 'Unknown'), $comments)));
    $by = count($authors) > 3 ? implode(', ', array_slice($authors, 0, 3)) . ' and others' : implode(', ', $authors);
    self::$ticketCardSummaryValues['comments'] = $total === 0 ? 'No comments' : self::plural($total, 'comment') . ' by ' . $by;
    $files = array_map(fn(array $attachment): string => (string)($attachment['filename'] ?? ''), self::$ticketAttachments);
    self::$ticketCardSummaryValues['attachments'] = $files === [] ? 'No attachments' : self::plural(count($files), 'attachment') . ': ' . self::firstThree($files);
    $related = self::$relatedTicketKeys === [] ? 'No related tickets' : self::plural(count(self::$relatedTicketKeys), 'related ticket') . ': ' . self::firstThree(self::$relatedTicketKeys);
    if (self::$ticketKey !== '' && self::$data->cachedChildren(self::$ticketKey) === null) {
      $related .= '; children not loaded yet (Return loads them)';
    }
    self::$ticketCardSummaryValues['related'] = $related;
  }

  /** Format a count with a singular or plural noun. */
  private static function plural(int $count, string $noun): string {
    return $count . ' ' . $noun . ($count === 1 ? '' : 's');
  }

  /** Attachment token conversion for the current ticket. */
  private static function media(): TicketMedia {
    return new TicketMedia(self::$ticket, self::$data);
  }

  /** Name embedded files in the open Description or Comments card; unknown ids cost one lookup per attachment. */
  private static function resolveTicketMedia(): void {
    $card = self::openTicketCard();
    if (self::$ticketKey === '' || !in_array($card, ['description', 'comments'], true)) {
      return;
    }
    $fields = is_array(self::$ticket['fields'] ?? null) ? self::$ticket['fields'] : [];
    $media = self::media();
    $text = $media->toText($fields['description'] ?? null);
    if ($card === 'comments') {
      $text = '';
      foreach ((array)($fields['comment']['comments'] ?? []) as $comment) {
        $text .= $media->toText($comment['body'] ?? null) . "\n";
      }
    }
    if (!$media->needsLookup($text)) {
      return;
    }
    if (self::request('GET attachment media ids for ' . self::$ticketKey, static fn() => $media->resolve($text))) {
      self::renderTicket();
    }
  }

  /** Join the first three items and mark that more exist. */
  private static function firstThree(array $items): string {
    return implode(', ', array_slice($items, 0, 3)) . (count($items) > 3 ? ', …' : '');
  }

  /** Show fields, comments, attachments, and related issues of the current ticket. */
  private static function renderTicket(): void {
    $fields = is_array(self::$ticket['fields'] ?? null) ? self::$ticket['fields'] : [];
    self::title('ticket', 'ticket-key')->setText(self::$ticketKey === '' ? 'Ticket' : self::$ticketKey);
    self::input('ticket', 'ticket-summary')->setValue((string)($fields['summary'] ?? ''));
    self::editor('ticket', 'ticket-description')->setValue(self::media()->toText($fields['description'] ?? null));
    self::text('ticket', 'ticket-comments')->setText(self::$ticketKey === '' ? '' : self::ticketCommentsText());
    $attachments = [];
    self::$ticketAttachments = [];
    foreach ($fields['attachment'] ?? [] as $attachment) {
      if (is_array($attachment)) {
        self::$ticketAttachments[] = $attachment;
        $attachments[] = [(string)($attachment['author']['displayName'] ?? ''), (string)($attachment['filename'] ?? ''), self::attachmentSize((int)($attachment['size'] ?? 0)) . '  ' . substr((string)($attachment['created'] ?? ''), 0, 10)];
      }
    }
    self::table('ticket', 'ticket-attachments')->setRows(['Author', 'File', 'Size / created'], $attachments);
    self::renderRelatedTickets($fields);
    self::renderTicketCardSummaries($fields);
    foreach (self::$ticketProperties as $key => $summary) {
      $value = self::$ticketKey === '' ? '' : FieldConfiguration::value(self::$ticket, $key);
      $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
      $summary->setValue($value !== '' ? $value : '-');
    }
    self::resolveTicketMedia();
  }

  /** Let the preview body occupy the entire window, without selector buttons. */
  private static function prepareAttachmentPreview(): void {
    $screen = self::$window->screen('attachment-preview');
    $body = $screen->layout->findNode('attachment-preview-body');
    if ($body === null) {
      throw new \LogicException('Attachment preview layout is missing.');
    }
    $screen->setLayout($body);
  }

  /** Open the selected attachment on a full-window screen. */
  public static function openAttachmentPreview(EventContext $event): void {
    self::showAttachmentPreview(self::table('ticket', 'ticket-attachments')->cursorRow());
  }

  /** Advance through the ticket's attachment rows while previewing. */
  public static function nextAttachmentPreview(EventContext $event): bool {
    return self::moveAttachmentPreview(1);
  }

  /** Go back through the ticket's attachment rows while previewing. */
  public static function previousAttachmentPreview(EventContext $event): bool {
    return self::moveAttachmentPreview(-1);
  }

  private static function moveAttachmentPreview(int $offset): bool {
    if (self::$window->currentScreenId() !== 'attachment-preview' || self::$attachmentPreviewRow === null) {
      return false;
    }
    $row = self::$attachmentPreviewRow + $offset;
    if (!isset(self::$ticketAttachments[$row])) {
      return true;
    }
    self::showAttachmentPreview($row);
    return true;
  }

  private static function showAttachmentPreview(int $row): void {
    $attachment = self::$ticketAttachments[$row] ?? null;
    if ($attachment === null) {
      return;
    }
    self::table('ticket', 'ticket-attachments')->setCursor($row);
    $name = (string)($attachment['filename'] ?? 'Attachment');
    $kind = AttachmentPreview::kind($attachment);
    if ($kind === null) {
      self::$window->setCurrentScreenId('ticket');
      self::status('Preview not supported: ' . $name, true);
      return;
    }
    self::replaceAttachmentPreview(new Text('Loading attachment...'), 'Text');
    self::$window->setCurrentScreenId('attachment-preview');
    self::$window->refreshLayout();
    try {
      $widget = AttachmentPreview::widget($kind, self::$data->attachmentPath($attachment), $name);
    } catch (\Throwable $error) {
      self::$window->setCurrentScreenId('ticket');
      if ($error->getCode() === 404) {
        self::reloadStaleTicket($name);
        return;
      }
      self::status('Load attachment ' . $name . ' failed: ' . $error->getMessage(), true);
      self::$window->refreshLayout();
      return;
    }
    self::replaceAttachmentPreview($widget, ucfirst($kind));
    self::$attachmentPreviewRow = $row;
    self::$window->screen('attachment-preview')->activateLeaf(self::$attachmentPreviewLeaf);
    self::$window->refreshLayout();
  }

  /** Reload a cached ticket whose attachment was deleted in Jira. */
  private static function reloadStaleTicket(string $name): void {
    $key = self::$ticketKey;
    if (self::request('GET ' . $key, static function() use ($key): void {
      self::$ticket = self::$data->ticket($key, true);
      self::renderTicket();
    })) {
      self::status($name . ' no longer exists in Jira; the ticket was reloaded.', true);
    }
  }

  /** Swap the content of the full-window preview without changing screens. */
  private static function replaceAttachmentPreview(Widget $widget, string $type): void {
    $widget->setId('attachment-preview-content');
    $leaf = new LayoutLeaf($type, '1*', '1*', $widget);
    $screen = self::$window->screen('attachment-preview');
    $body = $screen->layout->findNode('attachment-preview-body');
    $old = self::$attachmentPreviewLeaf ?? $body->leaves()[0];
    if (!$body->replaceChild($old, $leaf)) {
      throw new \LogicException('Attachment preview layout is missing.');
    }
    self::$attachmentPreviewLeaf = $leaf;
    $screen->setLayout($screen->layout);
  }

  /** Return to the ticket while preserving its attachment row. */
  public static function closeAttachmentPreview(EventContext $event): bool {
    if (self::$window->currentScreenId() !== 'attachment-preview') {
      return false;
    }
    self::$window->setCurrentScreenId('ticket');
    return true;
  }

  /** Format the current issue's comment history for the viewer. */
  private static function ticketCommentsText(): string {
    $lines = [];
    self::$ticketCommentRanges = [];
    foreach (self::$ticket['fields']['comment']['comments'] ?? [] as $comment) {
      if (!is_array($comment)) {
        continue;
      }
      if ($lines !== []) {
        array_push($lines, '', '');
      }
      $start = count($lines);
      $author = (string)($comment['author']['displayName'] ?? 'Unknown');
      $date = (string)($comment['created'] ?? '');
      if ($date !== '') {
        try {
          $date = (new \DateTimeImmutable($date))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
          $date = (string)($comment['created'] ?? '');
        }
      }
      $lines[] = ">>> {$author} [{$date}]";
      $lines[] = '';
      foreach (explode("\n", self::media()->toText($comment['body'] ?? null)) as $line) {
        $lines[] = $line;
      }
      self::$ticketCommentRanges[] = [$start, count($lines), $comment];
    }
    return $lines === [] ? '(no comments)' : implode("\n", $lines);
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
    foreach (self::$ticketKey === '' ? [] : self::$data->cachedChildren(self::$ticketKey) ?? [] as $issue) {
      self::addRelatedTicket($rows, 'Child', $issue);
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
    return self::$window->screen($screen)->widget($id);
  }
}
