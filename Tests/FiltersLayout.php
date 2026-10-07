<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';
require_once APP_DIR . '/App/Controller.php';

use MAJIRA\App\Controller;
use MAJIRA\App\FilterSummary;
use SPTK\Core\Window;
use SPTK\Core\Style;
use SPTK\Layout\{LayoutLeaf, LayoutSeparator, Tile};
use SPTK\SDLWrapper\SDL;
use SPTK\Rendering\{Grid, GridWriter};
use SPTK\Widgets\DateSelector\DateSelector;
use SPTK\XmlParser\ScreenParser;

$screen = (new ScreenParser())->parse('filters.xml', new Style(), 'filters', 'Filters');
$column = $screen->layout->findNode('filter-tiles');
if ($column === null || $screen->widget('custom-filters') === null || $screen->widget('custom-name') === null) {
  throw new RuntimeException('Three filter columns must be present.');
}
$children = (new ReflectionProperty($column, 'children'))->getValue($column);
if (count(array_filter($children, fn($child): bool => $child instanceof LayoutSeparator)) !== 9) {
  throw new RuntimeException('Filter tiles must have a separator between each pair.');
}
$keys = ['project', 'board', 'sprint', 'search', 'updated', 'created', 'status', 'type', 'priority', 'assignee'];
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
  if ($key !== 'project') {
    $column->replaceChild($expanded[$key], $compact[$key]);
  }
}
$screen->setLayout($screen->layout);
$screen->measureGrid(new Tile(0, 0, 132, 54));
if ($expanded['project']->leaves()[0]->grid()->height <= 2) {
  throw new RuntimeException('The selected filter must fill the remaining height.');
}
$projectWidget = $expanded['project']->leaves()[0]->instance();
$column->replaceChild($expanded['project'], $compact['project']);
$column->replaceChild($compact['status'], $expanded['status']);
$screen->setLayout($screen->layout);
$screen->measureGrid(new Tile(0, 0, 132, 54));
if ($compact['project']->grid()->height !== 2 || $expanded['status']->leaves()[0]->grid()->height <= 2) {
  throw new RuntimeException('Switching tiles must collapse the previous one and expand the selected one.');
}
$column->replaceChild($compact['project'], $expanded['project']);
$screen->setLayout($screen->layout);
if ($screen->widget('projects') !== $projectWidget) {
  throw new RuntimeException('Switching tiles must retain widget state.');
}
$navigation = (new ScreenParser())->parse('filters.xml', new Style(), 'filters', 'Filters');
$navigation->measureGrid(new Tile(0, 0, 132, 54));
$window = (new ReflectionClass(Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Window::class, 'screens'))->setValue($window, [$navigation]);
(new ReflectionProperty(Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(Controller::class, 'filterExpanded'))->setValue(null, [
  'project' => $navigation->layout->findNode('filter-project'),
  'status' => $navigation->layout->findNode('filter-status'),
]);
$right = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RIGHT, 'mod' => 0]];
$left = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_LEFT, 'mod' => 0]];
if (!$navigation->handleEvent($right) || $navigation->selectedLeaf()->instance() !== $navigation->widget('projects')) {
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
$slot = $navigation->layout->findNode('updated-from-slot');
$calendar = $slot->leaves()[0];
$placeholder = new FilterSummary('From');
$placeholder->setId('date-placeholder-updated-from');
$empty = new LayoutLeaf('FilterSummary', '1*', '1*', $placeholder);
$slot->replaceChild($calendar, $empty);
(new ReflectionProperty(Controller::class, 'dateSlots'))->setValue(null, [
  'updated' => ['from' => ['slot' => $slot, 'date' => $calendar, 'empty' => $empty, 'enabled' => false]],
]);
(new ReflectionProperty(Controller::class, 'filterWidgets'))->setValue(null, ['updated-date' => $calendar->instance()]);
$navigation->setLayout($navigation->layout);
$navigation->measureGrid(new Tile(0, 0, 132, 54));
$navigation->selectLeaf($empty);
$enter = (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_RETURN, 'mod' => 0]];
if (!$navigation->handleEvent($enter) || $slot->leaves()[0] !== $calendar) {
  throw new RuntimeException('Return on an empty date slot must add its calendar.');
}
$navigation->setLayout($navigation->layout);
$navigation->measureGrid(new Tile(0, 0, 132, 54));
$navigation->activateLeaf($calendar);
$delete = (object)['type' => SDL::SDL_EVENT_KEY_UP, 'key' => (object)['key' => SDL::KEY_DELETE, 'mod' => 0]];
if (!$navigation->handleEvent($delete) || $slot->leaves()[0] !== $empty || $navigation->activeLeaf() !== null) {
  throw new RuntimeException('Delete on an active calendar must empty its date slot.');
}
echo "Filters layout OK\n";
