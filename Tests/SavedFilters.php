<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{FilterState, JqlBuilder, Settings};

function expectSavedFilter(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$testHome = sys_get_temp_dir() . '/majira-saved-filter-test-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
Settings::save([]);
$filters = FilterState::defaults();
$filters['customFilters'] = [
  ['name' => 'new', 'jql' => '', 'form' => FilterState::formValues($filters), 'scope' => FilterState::scopeValues([])],
  ['name' => 'Release', 'jql' => 'status in ("Open")', 'form' => FilterState::formValues(['status' => ['Open'], 'search' => ['text' => 'launch']]), 'scope' => FilterState::scopeValues(['projectKey' => 'AP'])],
  ['name' => 'release', 'jql' => 'status in ("Closed")'],
];
FilterState::save($filters);
$loaded = FilterState::load();
expectSavedFilter(count($loaded['customFilters']) === 2, 'Saved filter names must be unique regardless of case.');
expectSavedFilter($loaded['customFilters'][0]['jql'] === '' && $loaded['customFilters'][0]['form']['status'] === [], 'A new blank filter must survive persistence.');
expectSavedFilter($loaded['customFilters'][1]['form']['status'] === ['Open'] && $loaded['customFilters'][1]['form']['search']['text'] === 'launch', 'Saved form choices must survive persistence.');
expectSavedFilter($loaded['customFilters'][1]['scope']['projectKey'] === 'AP', 'Saved project scope must survive persistence.');
expectSavedFilter(FilterState::normalize(['selectedCustomFilter' => 'Missing', 'mode' => 'jql'])['selectedCustomFilter'] === '', 'A missing saved filter must leave the current draft unnamed.');
expectSavedFilter($loaded['customFilters'][0]['mode'] === 'builder', 'New filters must start in builder mode.');
expectSavedFilter($loaded['customFilters'][1]['mode'] === 'jql', 'Existing nonempty saved queries must remain editable as JQL.');
$loaded['selectedCustomFilter'] = 'Release';
FilterState::save($loaded);
expectSavedFilter(FilterState::load()['mode'] === 'jql', 'Selecting a JQL filter must restore its mode.');
$loaded['selectedCustomFilter'] = 'new';
FilterState::save($loaded);
expectSavedFilter(FilterState::load()['mode'] === 'builder', 'Selecting another filter must restore its own builder mode.');
$loaded['selectedCustomFilter'] = 'Release';
$loaded['mode'] = 'jql';
$loaded['customFilters'][1]['mode'] = 'jql';
$loaded['customFilters'][1]['jql'] = 'status = "In Review"';
FilterState::save($loaded);
expectSavedFilter((new JqlBuilder())->current() === 'status = "In Review"', 'A hand-edited saved JQL must be used as the current query.');
$loaded = FilterState::load();
expectSavedFilter($loaded['customFilters'][1]['mode'] === 'jql', 'JQL mode must survive persistence and selection.');
$loaded = FilterState::load();
$loaded['status'] = ['Open'];
$loaded['selectedCustomFilter'] = '';
$loaded['mode'] = 'jql';
$loaded['customJql'] = '';
$loaded['customJqlEdited'] = true;
FilterState::save($loaded);
expectSavedFilter((new JqlBuilder())->current() === '', 'Clearing a hand-edited JQL must not restore generated clauses.');
$loaded['mode'] = 'builder';
FilterState::save($loaded);
expectSavedFilter((new JqlBuilder())->current() === 'status in ("Open")', 'Builder mode must use its values rather than the previous manual JQL.');
$builder = FilterState::defaults();
$builder['status'] = ['Open'];
$builder['search']['text'] = 'launch';
$builder['updated'] = '2026-10-01';
$builder['orderBy'] = [['field' => 'updated', 'direction' => 'DESC']];
$builder['lastJql'] = 'text ~ "earlier"';
$builder['selectedCustomFilter'] = 'Release';
$builder['customFilters'] = [
  ['name' => 'Release', 'jql' => 'status in ("Open")', 'mode' => 'builder', 'form' => FilterState::formValues($builder), 'scope' => FilterState::scopeValues(['projectKey' => 'AP'])],
  ['name' => 'Other', 'jql' => 'priority = High', 'mode' => 'jql'],
];
$cleared = FilterState::clearCurrent($builder);
expectSavedFilter($cleared['selectedCustomFilter'] === 'Release' && $cleared['mode'] === 'builder' && $cleared['lastJql'] === 'text ~ "earlier"', 'Clearing a builder filter must retain its identity and unrelated JQL history.');
expectSavedFilter($cleared['status'] === [] && $cleared['search'] === FilterState::defaultSearch() && $cleared['updated'] === '' && $cleared['orderBy'] === [], 'Clearing a builder filter must reset its values.');
expectSavedFilter($cleared['customFilters'][0]['jql'] === '' && $cleared['customFilters'][0]['form'] === FilterState::formValues(FilterState::defaults()) && $cleared['customFilters'][0]['scope'] === FilterState::scopeValues([]), 'Clearing a builder filter must update its saved row.');
expectSavedFilter($cleared['customFilters'][1]['jql'] === 'priority = High', 'Clearing one filter must preserve other saved filters.');
Settings::save(array_replace(Settings::load(), FilterState::scopeValues([])));
FilterState::save($cleared);
expectSavedFilter((new JqlBuilder())->current() === '' && FilterState::load()['selectedCustomFilter'] === 'Release', 'A cleared saved builder filter must remain selected with an empty query.');
$raw = FilterState::defaults();
$raw['mode'] = 'jql';
$raw['selectedCustomFilter'] = 'Raw';
$raw['customFilters'] = [['name' => 'Raw', 'jql' => 'project = AP', 'mode' => 'jql']];
$clearedRaw = FilterState::clearCurrent($raw);
expectSavedFilter($clearedRaw['selectedCustomFilter'] === 'Raw' && $clearedRaw['mode'] === 'jql' && $clearedRaw['customFilters'][0]['jql'] === '', 'Clearing a named JQL filter must retain its name and mode while emptying its query.');
$raw['selectedCustomFilter'] = '';
$raw['customJql'] = 'project = AP';
$raw['customJqlEdited'] = true;
$clearedRaw = FilterState::clearCurrent($raw);
expectSavedFilter($clearedRaw['mode'] === 'jql' && $clearedRaw['customJql'] === '' && $clearedRaw['customJqlEdited'], 'Clearing an unnamed JQL filter must keep its blank query authoritative.');
$rows = FilterState::defaults();
$rows['customFilters'] = [
  ['name' => 'First', 'jql' => 'project = A', 'mode' => 'builder'],
  ['name' => 'Middle', 'jql' => 'project = B', 'mode' => 'jql'],
  ['name' => 'Last', 'jql' => 'project = C', 'mode' => 'builder'],
];
$removedFirst = FilterState::removeSavedFilter($rows, 'First');
expectSavedFilter($removedFirst['selectedCustomFilter'] === 'Middle' && $removedFirst['mode'] === 'jql' && count($removedFirst['customFilters']) === 2, 'Deleting a filter must select the next row and restore its mode.');
$removedLast = FilterState::removeSavedFilter($rows, 'Last');
expectSavedFilter($removedLast['selectedCustomFilter'] === 'Middle' && $removedLast['mode'] === 'jql', 'Deleting the last filter must select the preceding row.');
$removedOnly = FilterState::removeSavedFilter(['customFilters' => [['name' => 'Only', 'jql' => 'project = A', 'mode' => 'jql']]], 'Only');
expectSavedFilter($removedOnly['selectedCustomFilter'] === '' && $removedOnly['mode'] === 'builder' && $removedOnly['customFilters'] === [], 'Deleting the only saved filter must leave a blank builder.');
$reordered = FilterState::reorderSavedFilters($rows, ['Last', 'First', 'Middle']);
expectSavedFilter(array_column($reordered['customFilters'], 'name') === ['Last', 'First', 'Middle'] && $reordered['customFilters'][0]['jql'] === 'project = C' && $reordered['selectedCustomFilter'] === '', 'Reordering must preserve saved row data and selection.');
expectSavedFilter(FilterState::reorderSavedFilters($rows, ['First', 'First', 'Middle'])['customFilters'] === FilterState::normalize($rows)['customFilters'], 'An invalid order must leave saved filters unchanged.');
foreach (glob($testHome . '/.majira/*') ?: [] as $file) {
  unlink($file);
}
rmdir($testHome . '/.majira');
rmdir($testHome);
echo "Saved filters OK\n";
