<?php

define('APP_DIR', dirname(__DIR__));
require_once APP_DIR . '/SPTK/App.php';

use SPTK\Core\Style;
use SPTK\Layout\{LayoutNode, Tile};
use SPTK\Widgets\Button\Button;
use SPTK\XmlParser\ScreenParser;

/** Check the XML sidebar can be removed and restored without replacing the table. */
function expectListLayout(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$screen = (new ScreenParser())->parse('list.xml', new Style(), 'list', 'List');
$previousFocus = $screen->selectedLeaf();
$body = $screen->layout->findNode('list-body');
expectListLayout($body !== null, 'List body must be named in XML.');
$table = $screen->widget('tickets');
$tableLeaf = null;
foreach ($body->leaves() as $leaf) {
  if ($leaf->instance() === $table) {
    $tableLeaf = $leaf;
    break;
  }
}
expectListLayout($tableLeaf !== null, 'List body must contain the ticket table.');
expectListLayout($screen->widget('sidebar-filters') !== null, 'Sidebar filters must be declared in XML.');
expectListLayout($screen->widget('scope') === null, 'List must not reserve space for scope labels before JQL.');
expectListLayout($screen->layout->leaves()[0]->name() === 'Input', 'JQL input must be the first List tile.');
expectListLayout(count($body->leaves()) === 2, 'Sidebar must contain only its filter list.');
$filterButtons = array_filter($screen->layout->leaves(), fn($leaf): bool => $leaf->instance() instanceof Button && $leaf->instance()->hotkey() === 'f');
expectListLayout(count($filterButtons) === 1, 'Filters button must have the F hotkey.');
$grid = new Tile(0, 0, 132, 54);
$screen->measureGrid($grid);
$openWidth = $tableLeaf->grid()->width;
$openHeight = $tableLeaf->grid()->height;
$tableOnly = new LayoutNode('horizontal', '1*', '1*');
$tableOnly->addLeaf($tableLeaf);
expectListLayout($screen->layout->replaceChild($body, $tableOnly), 'List body must be removable.');
$screen->setLayout($screen->layout);
$screen->measureGrid($grid);
expectListLayout($screen->widget('sidebar-filters') === null, 'Hidden sidebar must leave screen focus and widget lookup.');
expectListLayout($tableLeaf->grid()->width > $openWidth, 'Hidden sidebar must give the table more width.');
expectListLayout($tableLeaf->grid()->height === $openHeight, 'Hidden sidebar must leave the table at full height.');
$accepted = false;
$table->on('accept', function() use (&$accepted): void {
  $accepted = true;
});
$screen->activateLeaf($tableLeaf);
$screen->release('cancel');
expectListLayout(!$accepted && $screen->activeLeaf() === null, 'Opening filters must release the table without accepting its ticket.');
expectListLayout($screen->layout->replaceChild($tableOnly, $body), 'List body must be restorable.');
$screen->setLayout($screen->layout);
$screen->measureGrid($grid);
expectListLayout($screen->widget('tickets') === $table, 'Restoring the sidebar must preserve the table widget.');
expectListLayout($screen->widget('sidebar-filters') !== null, 'Restoring the sidebar must restore its XML widgets.');
$sidebar = $screen->widget('sidebar-filters');
foreach ($body->leaves() as $leaf) {
  if ($leaf->instance() === $sidebar) {
    $screen->selectLeaf($leaf);
    break;
  }
}
expectListLayout($screen->selectedLeaf()->instance() === $sidebar, 'Opening the sidebar must allow focus on its list.');
expectListLayout($screen->layout->replaceChild($body, $tableOnly), 'Sidebar must be removable again.');
$screen->setLayout($screen->layout);
$screen->selectLeaf($previousFocus);
expectListLayout($screen->selectedLeaf() === $previousFocus, 'Closing without applying must restore prior focus.');
$screen->activateLeaf($tableLeaf);
expectListLayout($screen->activeLeaf() === $tableLeaf, 'After applying, the table must be focused and active.');
echo "List layout OK\n";
