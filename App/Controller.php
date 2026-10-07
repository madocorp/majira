<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Adf;
use SPTK\App;
use SPTK\Core\Window;
use SPTK\Events\EventContext;
use SPTK\Events\EventDefinition;
use SPTK\Layout\{LayoutLeaf, LayoutNode};
use SPTK\Widgets\DateSelector\DateSelector;
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
  private static array $meta = [];
  private static array $ticket = [];
  private static string $ticketKey = '';
  private static ?LayoutLeaf $listTableLeaf = null;
  private static ?LayoutLeaf $listFocusBeforeSidebar = null;
  private static ?LayoutNode $listTableOnly = null;
  private static ?LayoutNode $listSidebar = null;
  private static bool $sidebarOpen = false;
  private static ?string $sidebarSelection = null;
  private const FILTER_TILES = ['project', 'board', 'sprint', 'search', 'updated', 'created', 'status', 'type', 'priority', 'assignee'];
  private static array $filterExpanded = [];
  private static array $filterCompact = [];
  private static array $filterWidgets = [];
  private static array $dateSlots = [];
  private static string $activeFilterTile = 'project';

  /** Restore cached data after the window opens. */
  public static function initialize(EventContext $event): void {
    self::$window = array_values(App::eventLoop()->windows())[0];
    self::$data = new JiraData();
    self::prepareListSidebar();
    self::prepareFilterTiles();
    $cached = TicketCache::load();
    self::$issues = is_array($cached['issues'] ?? null) ? $cached['issues'] : [];
    self::$meta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
    $settings = Settings::load();
    foreach (['jira-site' => 'site', 'jira-email' => 'email'] as $id => $key) {
      self::input('settings', $id)->setValue((string)($settings[$key] ?? ''));
    }
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::renderNavigation();
    self::renderTickets();
    self::renderFilters();
    self::status(Settings::isConfigured() ? 'Cached Jira data ready. Refresh for new results.' : 'Set Jira connection in Settings.');
    if (Settings::isConfigured()) {
      if (ProjectCache::load() === []) {
        self::reloadProjects($event);
      }
      self::loadMissingNavigationChoices();
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
    $filters['selectedCustomFilter'] = '';
    FilterState::save($filters);
    self::input('list', 'jql')->setValue($filters['lastJql']);
    self::status('Restored Last JQL.');
    return true;
  }

  /** Fetch a fresh first page. */
  public static function refreshTickets(EventContext $event): void {
    if (self::loadTickets(false)) {
      self::reportTicketCount();
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
    $project = (string)(Settings::load()['projectKey'] ?? '');
    if ($project === '') {
      self::status('Select a project before creating a ticket.', true);
      return;
    }
    self::title('create', 'create-project')->setText('New ticket in ' . $project);
    $types = self::$data->cachedIssueTypes($project);
    if ($types === []) {
      self::reloadIssueTypes($event);
    } else {
      self::renderIssueTypes($types);
    }
    self::$window->setCurrentScreenId('create');
  }

  /** Refresh issue types from Jira for the selected project. */
  public static function reloadIssueTypes(EventContext $event): void {
    $project = (string)(Settings::load()['projectKey'] ?? '');
    if ($project === '') {
      self::status('Select a project first.', true);
      return;
    }
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
    self::renderFilterOptions();
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::status('Project: ' . ($value === '' ? 'Any project' : $value));
    self::refreshFilterSummaries();
    self::loadMissingNavigationChoices(true);
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
    self::renderFilterOptions();
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::status('Board for sprint choices: ' . ($value ?: 'None selected'));
    self::refreshFilterSummaries();
    self::loadMissingNavigationChoices();
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
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::status('Sprint: ' . ($value ?: 'Any sprint'));
    self::refreshFilterSummaries();
  }

  /** Refresh the project cache from Jira. */
  public static function reloadProjects(EventContext $event): void {
    self::request('GET projects', function(): void {
      self::$data->projects();
      self::renderNavigation();
    });
  }

  /** Refresh boards for the selected project, or across projects. */
  public static function reloadBoards(EventContext $event): void {
    $key = (string)(Settings::load()['projectKey'] ?? '');
    self::request('GET boards for ' . ($key === '' ? 'all projects' : $key), function() use ($key): void {
      self::$data->boards($key);
      self::renderNavigation();
    });
  }

  /** Refresh sprints for the selected board. */
  public static function reloadSprints(EventContext $event): void {
    $id = (string)(Settings::load()['boardId'] ?? '');
    if ($id === '') {
      self::status('Select a board first.', true);
      return;
    }
    self::request('GET sprints for board ' . $id, function() use ($id): void {
      self::$data->sprints($id);
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
      TicketHistory::clear();
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
    self::status('Jira connection saved.');
    if ($changed) {
      self::reloadProjects($event);
    }
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
    self::renderNavigation();
    self::renderTickets();
    self::status('Jira caches cleared.');
  }

  /** Stop the application event loop. */
  public static function quit(EventContext $event): void {
    App::eventLoop()->stop();
  }

  /** Persist selected values for one filter group. */
  public static function saveFilterGroup(EventContext $event): void {
    $id = $event->widget?->id() ?? '';
    $group = str_ends_with($id, '-options') ? substr($id, 0, -strlen('-options')) : self::$activeFilterTile;
    if (!in_array($group, ['status', 'type', 'priority', 'assignee'], true)) {
      self::status('Select a choice filter first.', true);
      return;
    }
    $filters = FilterState::load();
    $filters[$group] = self::list('filters', $group . '-options')->getValue();
    $filters['customJql'] = '';
    $filters['selectedCustomFilter'] = '';
    FilterState::save($filters);
    self::refreshFilterSummaries();
    self::status(ucfirst($group) . ' filter saved.');
  }

  /** Refresh project filter choices even when already cached. */
  public static function loadFilterOptions(EventContext $event): void {
    $project = (string)(Settings::load()['projectKey'] ?? '');
    self::request('GET filter options for ' . ($project === '' ? 'any project' : $project), function() use ($project): void {
      self::$data->filterOptions($project, true);
      self::renderFilterOptions();
    });
  }

  /** Save the staged choices under a name and apply their generated JQL. */
  public static function applyFilters(EventContext $event): void {
    $name = trim(self::input('filters', 'custom-name')->getValue());
    if ($name === '') {
      self::status('Name this filter before applying it.', true);
      return;
    }
    $filters = FilterState::load();
    $filters['search']['text'] = trim(self::input('filters', 'search')->getValue());
    foreach (['updated', 'created'] as $field) {
      $from = self::$dateSlots[$field]['from']['enabled'] ? self::date('filters', $field . '-date')->getValue() : '';
      $to = self::$dateSlots[$field]['to']['enabled'] ? self::date('filters', $field . '-to-date')->getValue() : '';
      if ($from !== '' && $to !== '' && $from > $to) {
        self::status(ucfirst($field) . ': From must be on or before Through.', true);
        return;
      }
      $filters[$field] = $from;
      $filters[$field . 'To'] = $to;
    }
    foreach (['status', 'type', 'priority', 'assignee'] as $group) {
      $filters[$group] = self::list('filters', $group . '-options')->getValue();
    }
    $jql = (new JqlBuilder())->generatedFor($filters);
    if ($jql === '') {
      self::status('Set at least one filter before saving it.', true);
      return;
    }
    self::storeNamedFilter($filters, $name, $jql, true);
    self::input('list', 'jql')->setValue($jql);
    self::$window->setCurrentScreenId('list');
    self::applyFilterAndFocusTable();
  }

  /** Clear filters but preserve saved custom JQL entries. */
  public static function clearFilters(EventContext $event): void {
    $current = FilterState::load();
    $filters = FilterState::defaults();
    $filters['customFilters'] = $current['customFilters'];
    $filters['lastJql'] = $current['lastJql'];
    FilterState::save($filters);
    self::renderFilters();
    self::input('list', 'jql')->setValue((new JqlBuilder())->current());
    self::status('Filters cleared.');
  }

  /** Store the current JQL under a reusable name. */
  public static function saveCustomFilter(EventContext $event): void {
    $name = trim(self::input('filters', 'custom-name')->getValue());
    $jql = trim(self::input('list', 'jql')->getValue());
    if ($name === '' || $jql === '') {
      self::status('Enter a filter name and JQL first.', true);
      return;
    }
    self::storeNamedFilter(FilterState::load(), $name, $jql, false);
    self::status('Saved filter: ' . $name);
  }

  /** Activate a saved JQL filter. */
  public static function useCustomFilter(EventContext $event): void {
    $name = self::list('filters', 'custom-filters')->getValue();
    if (!is_string($name) || $name === '') {
      return;
    }
    if (self::activateCustomFilter($name)) {
      self::$window->setCurrentScreenId('list');
      self::applyFilterAndFocusTable();
    }
  }

  /** Store one named query and optionally make it the active List query. */
  private static function storeNamedFilter(array $filters, string $name, string $jql, bool $activate): void {
    $filters['customFilters'] = array_values(array_filter($filters['customFilters'], fn(array $row): bool => $row['name'] !== $name));
    $filters['customFilters'][] = ['name' => $name, 'jql' => $jql];
    if ($activate) {
      $filters['selectedCustomFilter'] = $name;
      $filters['customJql'] = '';
    }
    FilterState::save($filters);
    self::renderCustomFilters();
  }

  /** Select a saved JQL query and update the List input. */
  private static function activateCustomFilter(string $name): bool {
    $filters = FilterState::load();
    $selected = FilterState::customFilterByName($name, $filters);
    if ($selected === null) {
      return false;
    }
    $filters['selectedCustomFilter'] = $name;
    $filters['customJql'] = '';
    FilterState::save($filters);
    self::input('list', 'jql')->setValue((string)$selected['jql']);
    self::status('Using saved filter: ' . $name);
    return true;
  }

  /** Delete a saved JQL filter. */
  public static function deleteCustomFilter(EventContext $event): void {
    $name = self::list('filters', 'custom-filters')->getValue();
    if (!is_string($name) || $name === '') {
      return;
    }
    $filters = FilterState::load();
    $filters['customFilters'] = array_values(array_filter($filters['customFilters'], fn(array $row): bool => $row['name'] !== $name));
    if ($filters['selectedCustomFilter'] === $name) {
      $filters['selectedCustomFilter'] = '';
    }
    FilterState::save($filters);
    self::renderCustomFilters();
    self::status('Deleted filter: ' . $name);
  }

  /** Reload the current ticket from Jira. */
  public static function refreshTicket(EventContext $event): void {
    if (self::$ticketKey !== '') {
      self::openTicket(self::$ticketKey, true);
    }
  }

  /** Save the summary field. */
  public static function saveSummary(EventContext $event): void {
    $summary = trim(self::input('ticket', 'ticket-summary')->getValue());
    if (self::$ticketKey === '' || $summary === '') {
      self::status('Summary cannot be empty.', true);
      return;
    }
    self::writeTicket(['summary' => $summary], 'PUT summary');
  }

  /** Save the description field. */
  public static function saveDescription(EventContext $event): void {
    if (self::$ticketKey !== '') {
      self::writeTicket(['description' => Adf::fromMarkdown(self::editor('ticket', 'ticket-description')->getValue())], 'PUT description');
    }
  }

  /** Add a comment and refresh the ticket. */
  public static function addComment(EventContext $event): void {
    $text = trim(self::editor('ticket', 'new-comment')->getValue());
    if (self::$ticketKey === '' || $text === '') {
      self::status('Enter a comment first.', true);
      return;
    }
    $saved = self::request('POST comment to ' . self::$ticketKey, function() use ($text): void {
      self::$data->client()->addComment(self::$ticketKey, Adf::fromMarkdown($text));
      self::editor('ticket', 'new-comment')->setValue('');
    });
    if ($saved) {
      self::openTicket(self::$ticketKey, true);
    }
  }

  /** Load tickets and show them only when the request succeeds. */
  private static function loadTickets(bool $more): bool {
    $jql = (new JqlBuilder())->current();
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
      self::status('Showing ' . $count . ' tickets; more available (M: next page).');
    } else {
      self::status($count === 0 ? 'No tickets found.' : 'Showing all ' . $count . ' tickets.');
    }
    self::$window->refreshLayout();
  }

  /** Open a cached or fresh ticket. */
  private static function openTicket(string $key, bool $refresh = false): void {
    $label = !$refresh && self::$data->hasCachedTicket($key) ? 'Open cached ' : 'GET Jira ticket ';
    self::request($label . $key, function() use ($key, $refresh): void {
      self::$ticket = self::$data->ticket($key, $refresh);
      self::$ticketKey = $key;
      TicketHistory::add(self::$ticket);
      if ($refresh) {
        self::refreshTicketRow();
      }
      self::renderTicket();
      self::$window->setCurrentScreenId('ticket');
    });
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
    self::status($label . ' ...');
    self::$window->refreshLayout();
    try {
      $operation();
      self::status($label . ' complete.');
    } catch (\Throwable $error) {
      self::status($label . ' failed: ' . $error->getMessage(), true);
      self::$window->refreshLayout();
      return false;
    }
    self::$window->refreshLayout();
    return true;
  }

  /** Notify every screen through its built-in status bar. */
  private static function status(string $message, bool $error = false): void {
    foreach (['list', 'filters', 'ticket', 'settings', 'create'] as $screen) {
      $bar = self::$window->screen($screen)?->statusBar;
      $error ? $bar?->error($message) : $bar?->notify($message);
    }
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
  private static function renderFilters(): void {
    $filters = FilterState::load();
    self::input('filters', 'search')->setValue($filters['search']['text']);
    foreach (['updated', 'created'] as $field) {
      self::date('filters', $field . '-date')->setValue($filters[$field] ?: date('Y-m-d'));
      self::date('filters', $field . '-to-date')->setValue($filters[$field . 'To'] ?: self::date('filters', $field . '-date')->getValue());
      self::showDateSlot($field, 'from', $filters[$field] !== '');
      self::showDateSlot($field, 'to', $filters[$field . 'To'] !== '');
    }
    self::renderFilterOptions();
    self::renderCustomFilters();
    self::refreshFilterSummaries();
  }

  /** Keep one full-height filter tile and two-line summaries for the others. */
  private static function prepareFilterTiles(): void {
    $screen = self::$window->screen('filters');
    $column = $screen->layout->findNode('filter-tiles');
    if ($column === null) {
      throw new \LogicException('Filter tile column is missing.');
    }
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
      $summary = new FilterSummary(ucfirst($key));
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
          self::refreshFilterSummaries();
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
          self::refreshFilterSummaries();
          return true;
        }
      }
    }
    return false;
  }

  /** Refresh the collapsed date summary after a calendar choice. */
  public static function dateSelectionChanged(EventContext $event): void {
    self::refreshFilterSummaries();
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
    if (!isset(self::$filterExpanded[$key]) || $key === self::$activeFilterTile) {
      return;
    }
    $previous = self::$activeFilterTile;
    $screen = self::$window->screen('filters');
    $column = $screen->layout->findNode('filter-tiles');
    $column->replaceChild(self::$filterExpanded[$previous], self::$filterCompact[$previous]);
    $column->replaceChild(self::$filterCompact[$key], self::$filterExpanded[$key]);
    self::$activeFilterTile = $key;
    $screen->setLayout($screen->layout);
    $screen->selectLeaf(self::$filterExpanded[$key]->leaves()[0]);
    self::$window->refreshLayout();
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

  /** Select the expanded widget while leaving it ready for Return. */
  private static function focusExpandedFilter(): bool {
    $screen = self::$window->screen('filters');
    $screen->selectLeaf(self::$filterExpanded[self::$activeFilterTile]->leaves()[0]);
    return true;
  }

  /** Show each filter's current choice beneath its name while collapsed. */
  private static function refreshFilterSummaries(): void {
    if (self::$filterCompact === []) {
      return;
    }
    $settings = Settings::load();
    $values = [
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
    self::selectKnown('filters', 'custom-filters', $filters['selectedCustomFilter']);
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

  /** Show fields, comments, and attachments of the current issue. */
  private static function renderTicket(): void {
    $fields = is_array(self::$ticket['fields'] ?? null) ? self::$ticket['fields'] : [];
    self::title('ticket', 'ticket-key')->setText(self::$ticketKey);
    self::input('ticket', 'ticket-summary')->setValue((string)($fields['summary'] ?? ''));
    self::editor('ticket', 'ticket-description')->setValue(Adf::toText($fields['description'] ?? null));
    $comments = [];
    foreach ($fields['comment']['comments'] ?? [] as $comment) {
      $author = (string)($comment['author']['displayName'] ?? 'Unknown');
      $date = substr((string)($comment['created'] ?? ''), 0, 16);
      $comments[] = $author . '  ' . $date . "\n" . Adf::toText($comment['body'] ?? null);
    }
    self::text('ticket', 'ticket-comments')->setText("Comments\n\n" . implode("\n\n", $comments));
    $attachments = [];
    foreach ($fields['attachment'] ?? [] as $attachment) {
      $attachments[] = (string)($attachment['filename'] ?? '');
    }
    self::text('ticket', 'ticket-attachments')->setText("Attachments\n" . implode("\n", $attachments));
    $properties = [
      'Status: ' . (string)($fields['status']['name'] ?? ''),
      'Type: ' . (string)($fields['issuetype']['name'] ?? ''),
      'Priority: ' . (string)($fields['priority']['name'] ?? ''),
      'Assignee: ' . (string)($fields['assignee']['displayName'] ?? 'Unassigned'),
      'Reporter: ' . (string)($fields['reporter']['displayName'] ?? ''),
      'Created: ' . (string)($fields['created'] ?? ''),
      'Updated: ' . (string)($fields['updated'] ?? ''),
      'Labels: ' . implode(', ', $fields['labels'] ?? []),
    ];
    self::text('ticket', 'ticket-properties')->setText(implode("\n", $properties));
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
