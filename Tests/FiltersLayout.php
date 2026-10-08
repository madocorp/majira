<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';
require_once APP_DIR . '/App/Controller.php';

use MAJIRA\App\Controller;
use MAJIRA\App\FilterSummary;
use SPTK\Core\Window;
use SPTK\Core\Style;
use SPTK\Layout\{LayoutLeaf, LayoutNode, LayoutSeparator, Tile};
use SPTK\SDLWrapper\SDL;
use SPTK\Rendering\{Grid, GridWriter};
use SPTK\Widgets\DateSelector\DateSelector;
use SPTK\Widgets\Button\Button;
use SPTK\Widgets\Input\Input;
use SPTK\Widgets\TextEditor\TextEditor;
use SPTK\XmlParser\ScreenParser;

$screen = (new ScreenParser())->parse('filters.xml', new Style(), 'filters', 'Filters');
$middle = $screen->layout->findNode('filter-middle');
$column = $screen->layout->findNode('filter-tiles');
if ($middle === null || $column === null || $screen->widget('custom-filters') === null || !$screen->widget('custom-name') instanceof Input || $screen->widget('filter-jql') !== null) {
  throw new RuntimeException('Three filter columns must be present.');
}
$saved = $screen->widget('custom-filters');
if ((new ReflectionProperty($saved, 'filterable'))->getValue($saved) || (new ReflectionProperty($saved, 'searchable'))->getValue($saved) || !(new ReflectionProperty($saved, 'reorderable'))->getValue($saved)) {
  throw new RuntimeException('Saved filters must allow reordering without text search.');
}
$middleChildren = (new ReflectionProperty($middle, 'children'))->getValue($middle);
if ($middleChildren[0] !== $column || $column->findNode('filter-name')->leaves()[0]->instance() !== $screen->widget('custom-name')) {
  throw new RuntimeException('The filter name input must be the first builder card.');
}
$children = (new ReflectionProperty($column, 'children'))->getValue($column);
if (count(array_filter($children, fn($child): bool => $child instanceof LayoutSeparator)) !== 10 || $children[0] !== $column->findNode('filter-name')) {
  throw new RuntimeException('The builder deck must start with Filter name.');
}
foreach (['project', 'board', 'sprint'] as $group) {
  $leaf = $column->findNode('filter-' . $group)->leaves()[0];
  $events = (new ReflectionProperty($leaf, 'events'))->getValue($leaf);
  $types = array_column($events, 'type');
  if (!in_array('accept', $types, true) || in_array('change', $types, true)) {
    throw new RuntimeException('Navigation choices must load only when list editing ends: ' . $group);
  }
}
$actions = $screen->layout->findNode('filter-actions');
$buttons = array_filter($actions->leaves(), fn($leaf): bool => $leaf->instance() instanceof Button);
$labels = array_map(fn($leaf): string => (new ReflectionProperty(Button::class, 'label'))->getValue($leaf->instance()), $buttons);
if (!in_array('Apply', $labels, true) || !in_array('Switch to JQL', $labels, true) || !in_array('Clear cache', $labels, true) || !in_array('Clear filter', $labels, true) || in_array('Clear filters', $labels, true) || in_array('Reload projects', $labels, true) || in_array('Reload boards', $labels, true) || in_array('Reload sprints', $labels, true) || in_array('Load filter options', $labels, true)) {
  throw new RuntimeException('Filter actions must offer one cache reset and no separate refresh controls.');
}
$hotkeys = [];
foreach ($buttons as $leaf) {
  $button = $leaf->instance();
  $hotkeys[$button->hotkey()] = (new ReflectionProperty(Button::class, 'label'))->getValue($button);
}
if ($hotkeys !== ['n' => 'New filter', 'j' => 'Switch to JQL', 'a' => 'Apply', 'd' => 'Delete filter', 'c' => 'Clear filter', 'r' => 'Clear cache']) {
  throw new RuntimeException('Filter actions need the N, J, A, D, C, and R shortcuts.');
}
$keys = ['name', 'project', 'board', 'sprint', 'search', 'updated', 'created', 'status', 'type', 'priority', 'assignee'];
foreach (['updated', 'created'] as $field) {
  if ($screen->widget($field . '-mode') !== null || !$screen->widget($field . '-date') instanceof DateSelector || !$screen->widget($field . '-to-date') instanceof DateSelector
    || $screen->widget($field . '-date')->preferredHeight() !== 10 || $screen->widget($field . '-to-date')->preferredHeight() !== 10
    || $column->findNode($field . '-from-slot') === null || $column->findNode($field . '-to-slot') === null) {
    throw new RuntimeException('Date filters need independent From and To calendar slots.');
  }
}
$fromSlot = $column->findNode('updated-from-slot');
$dateLeaf = $fromSlot->leaves()[0];
$dateWidget = $dateLeaf->instance();
$emptyLeaf = new LayoutLeaf('FilterSummary', '1*', '1*', new FilterSummary('From'));
$fromSlot->replaceChild($dateLeaf, $emptyLeaf);
$screen->setLayout($screen->layout);
if ($screen->widget('updated-date') !== null || $fromSlot->leaves()[0] !== $emptyLeaf) {
  throw new RuntimeException('An empty date slot must hide its calendar.');
}
$fromSlot->replaceChild($emptyLeaf, $dateLeaf);
$screen->setLayout($screen->layout);
if ($screen->widget('updated-date') !== $dateWidget) {
  throw new RuntimeException('Adding a date must restore the same calendar widget.');
}
$summary = new FilterSummary('Status');
$summary->setValue('-');
$grid = new Grid(20, 2);
$summary->paint(new GridWriter($grid, new Tile(0, 0, 20, 2)));
if ($grid->cell(0, 0)->fg != (new Style())->highlight || $grid->cell(0, 1)->glyph !== '-' || $grid->cell(0, 1)->fg != (new Style())->foreground) {
  throw new RuntimeException('Collapsed filters need a cyan title and a plain value line.');
}
$expanded = [];
$compact = [];
foreach ($keys as $key) {
  $expanded[$key] = $column->findNode('filter-' . $key);
  if ($expanded[$key] === null || ($key !== 'updated' && $key !== 'created' && count($expanded[$key]->leaves()) !== 1)) {
    throw new RuntimeException('Missing expanded filter tile: ' . $key);
  }
  $compact[$key] = new LayoutLeaf('FilterSummary', '1*', '2', new FilterSummary(ucfirst($key)));
  if ($key !== 'name') {
    $column->replaceChild($expanded[$key], $compact[$key]);
  }
}
$screen->setLayout($screen->layout);
$screen->measureGrid(new Tile(0, 0, 132, 54));
if ($expanded['name']->leaves()[0]->grid()->height <= 2) {
  throw new RuntimeException('The selected filter must fill the remaining height.');
}
$nameWidget = $expanded['name']->leaves()[0]->instance();
$projectWidget = $expanded['project']->leaves()[0]->instance();
$column->replaceChild($expanded['name'], $compact['name']);
$column->replaceChild($compact['status'], $expanded['status']);
$screen->setLayout($screen->layout);
$screen->measureGrid(new Tile(0, 0, 132, 54));
if ($compact['name']->grid()->height !== 2 || $expanded['status']->leaves()[0]->grid()->height <= 2) {
  throw new RuntimeException('Switching tiles must collapse the previous one and expand the selected one.');
}
$column->replaceChild($compact['name'], $expanded['name']);
$screen->setLayout($screen->layout);
if ($screen->widget('custom-name') !== $nameWidget || $expanded['project']->leaves()[0]->instance() !== $projectWidget) {
  throw new RuntimeException('Switching tiles must retain widget state.');
}
$jqlView = new LayoutNode('vertical', '1*', '1*', id: 'filter-jql-mode');
$jqlView->addLeaf(new LayoutLeaf('Input', '1*', 'auto', $nameWidget));
$jqlView->addSeparator(new LayoutSeparator());
$jqlEditor = new TextEditor('status = Open', title: 'JQL');
$jqlEditor->setId('filter-jql');
$jqlView->addLeaf(new LayoutLeaf('TextEditor', '1*', '1*', $jqlEditor));
$middle->replaceChild($column, $jqlView);
$screen->setLayout($screen->layout);
if ($screen->widget('custom-name') !== $nameWidget || $screen->widget('filter-jql') !== $jqlEditor || $screen->widget('projects') !== null || $screen->widget('status-options') !== null) {
  throw new RuntimeException('JQL mode must show the name and query while hiding builder values.');
}
$navigation = (new ScreenParser())->parse('filters.xml', new Style(), 'filters', 'Filters');
$navigation->measureGrid(new Tile(0, 0, 132, 54));
$window = (new ReflectionClass(Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Window::class, 'screens'))->setValue($window, [$navigation]);
(new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(Controller::class, 'filterExpanded'))->setValue(null, [
  'name' => $navigation->layout->findNode('filter-name'),
  'project' => $navigation->layout->findNode('filter-project'),
  'status' => $navigation->layout->findNode('filter-status'),
]);
$right = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RIGHT, 'mod' => 0]];
$left = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_LEFT, 'mod' => 0]];
if (!$navigation->handleEvent($right) || $navigation->selectedLeaf()->instance() !== $navigation->widget('custom-name')) {
  throw new RuntimeException('Right from saved filters must select the expanded tile.');
}
$navigation->activateLeaf($navigation->layout->leaves()[0]);
(new ReflectionProperty(Controller::class, 'activeFilterTile'))->setValue(null, 'status');
if (!$navigation->handleEvent($right) || $navigation->selectedLeaf()->instance() !== $navigation->widget('status-options') || $navigation->activeLeaf() !== null) {
  throw new RuntimeException('Right from the active saved list must follow the open tile.');
}
$actions = $navigation->layout->findNode('filter-actions');
$navigation->selectLeaf($actions->leaves()[1]);
if (!$navigation->handleEvent($left) || $navigation->selectedLeaf()->instance() !== $navigation->widget('status-options')) {
  throw new RuntimeException('Left from an action button must select the expanded tile.');
}
echo "Filters layout OK\n";
