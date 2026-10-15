<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\Highlighting\{MarkdownHighlighter, RuleSyntaxHighlighter};
use SPTK\Core\{Color, Style, TextCursor, TextRows};
use SPTK\Layout\Tile;
use SPTK\Rendering\{Grid, GridWriter};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\TextEditor\TextEditor;

/** Report a failed syntax highlighting expectation. */
function highlightCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Resolve the style covering one source grapheme. */
function highlightStyle(array $tokens, int $column): string {
  foreach ($tokens as $token) {
    if ($column >= $token['start'] && $column < $token['start'] + $token['length']) {
      return $token['style'];
    }
  }
  return 'plain';
}

$rules = RuleSyntaxHighlighter::fromRules([
  ['literal' => 'TODO', 'style' => 'keyword'],
  ['regex' => '/^\d+/u', 'style' => 'number'],
]);
highlightCheck($rules->highlight(['TODO 42'])[0] === [
  ['start' => 0, 'length' => 4, 'style' => 'keyword'],
  ['start' => 4, 'length' => 1, 'style' => 'plain'],
  ['start' => 5, 'length' => 2, 'style' => 'number'],
], 'Ordered literal and regex rules must preserve source positions.');

$markdown = new MarkdownHighlighter();
$lines = ['# Heading', '- **bold** and `code`', 'plain # heading', '```sql', 'SELECT *', '```', 'after'];
$tokens = $markdown->highlight($lines);
highlightCheck(highlightStyle($tokens[0], 0) === 'heading' && highlightStyle($tokens[0], 4) === 'heading', 'Markdown heading must be colored.');
highlightCheck(highlightStyle($tokens[1], 0) === 'list' && highlightStyle($tokens[1], 3) === 'strong' && highlightStyle($tokens[1], 17) === 'code', 'Markdown list, bold, and code must be colored.');
highlightCheck(highlightStyle($tokens[2], 6) === 'plain', 'Heading syntax is only special at line start.');
highlightCheck(highlightStyle($tokens[3], 0) === 'code-block' && highlightStyle($tokens[4], 0) === 'code-block' && highlightStyle($tokens[5], 0) === 'code-block', 'Fenced code must stay colored across lines.');
highlightCheck(highlightStyle($tokens[6], 0) === 'plain', 'Coloring must resume after a code fence.');
highlightCheck(highlightStyle($markdown->highlight(['👩‍💻 **yes**'])[0], 2) === 'strong', 'Token offsets must count grapheme clusters.');

$editor = new TextEditor('# Heading' . "\n" . '**bold** text', new Style(), false);
$editor->setHighlighter($markdown);
$grid = new Grid(32, 4);
$editor->paint(new GridWriter($grid, new Tile(0, 0, 32, 4)));
highlightCheck($grid->cell(0, 0)->bg == Color::from('#82b4d8'), 'The editor must paint heading colors.');
highlightCheck($grid->cell(0, 1)->bg == Color::from('#d2bf64'), 'The editor must paint bold colors.');
highlightCheck($grid->cell(9, 1)->bg == (new Style())->background, 'Plain text must retain the editor background.');
highlightCheck($editor->getValue() === '# Heading' . "\n" . '**bold** text', 'Highlighting must not change editable text.');
$header = new TextEditor(">>> Ada [2026-10-10 12:34:56]\n\nBody", new Style(), true);
$header->setHighlighter($markdown);
$headerGrid = new Grid(16, 5);
$header->paint(new GridWriter($headerGrid, new Tile(0, 0, 16, 5)));
highlightCheck($headerGrid->cell(15, 0)->bg == Color::from('#982461')
  && $headerGrid->cell(15, 1)->bg == Color::from('#982461'), 'Wrapped comment headers should fill each visible tile row.');
highlightCheck($headerGrid->cell(15, 3)->bg == (new Style())->background, 'Header fill should stop before the comment body.');
$code = new TextEditor("```\nSELECT *\n\n```\nafter", new Style(), false);
$code->setHighlighter($markdown);
$codeGrid = new Grid(20, 5);
$code->paint(new GridWriter($codeGrid, new Tile(0, 0, 20, 5)));
$codeBackground = Color::from('#b7d7e8');
highlightCheck($codeGrid->cell(19, 0)->bg == $codeBackground && $codeGrid->cell(19, 1)->bg == $codeBackground
  && $codeGrid->cell(19, 2)->bg == $codeBackground && $codeGrid->cell(19, 3)->bg == $codeBackground, 'A code block, including empty lines, should fill to the tile edge.');
highlightCheck($codeGrid->cell(19, 4)->bg == (new Style())->background, 'Code fill should stop after the closing fence.');
$editor->setValue('plain text');
$grid->clear();
$editor->paint(new GridWriter($grid, new Tile(0, 0, 32, 4)));
highlightCheck($grid->cell(0, 0)->bg == (new Style())->background, 'Replacing editor text must update syntax colors.');
$source = ['alpha beta gamma'];
$rows = (new TextRows())->build($source, 8, 'word-preserve', 8, true);
highlightCheck(array_column($rows, 'text') === ['alpha ', 'beta ', 'gamma'], 'Editable wrapping should break before whole words.');
highlightCheck(implode('', array_column($rows, 'text')) === $source[0], 'Editable wrapping must preserve every source character.');
$cursor = new TextCursor($source);
$cursor->setPosition(0, 6);
highlightCheck((new TextRows())->cursor($rows, $cursor) === [1, 0], 'The caret should map to the next wrapped word.');
$long = (new TextRows())->build(['extraordinary'], 5, 'word-preserve');
highlightCheck(array_column($long, 'text') === ['extra', 'ordin', 'ary'], 'An oversized word should split at the viewport edge.');
$wrapped = new TextEditor('- **bold**', new Style(), true);
$wrapped->setHighlighter($markdown);
$wrappedGrid = new Grid(8, 3);
$wrapped->paint(new GridWriter($wrappedGrid, new Tile(0, 0, 8, 3)));
highlightCheck($wrappedGrid->cell(0, 1)->glyph === '*' && $wrappedGrid->cell(0, 1)->bg == Color::from('#d2bf64'), 'Markdown colors should follow words onto wrapped rows.');
$wrapped->emit('activate');
$wrapped->handleInput((object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_DOWN, 'mod' => 0]]);
highlightCheck($wrapped->cursorPosition() === [0, 2], 'Down should move the caret to the next wrapped word.');
$links = (new MarkdownHighlighter())->highlight(['See <https://a.io/x> or [docs](https://b.io) not https://c.io'])[0];
highlightCheck(highlightStyle($links, 4) === 'link' && highlightStyle($links, 19) === 'link', 'An <url> autolink should use the link color.');
highlightCheck(highlightStyle($links, 24) === 'link' && highlightStyle($links, 43) === 'link', 'A [text](url) link should use the link color.');
highlightCheck(highlightStyle($links, 49) === 'plain', 'A bare URL is plain Markdown text.');
$ticketLink = (new MarkdownHighlighter())->highlight(['See <#AP-1234> now'])[0];
highlightCheck(highlightStyle($ticketLink, 4) === 'link' && highlightStyle($ticketLink, 13) === 'link' && highlightStyle($ticketLink, 15) === 'plain', 'A <#KEY> ticket link should use the link color.');
echo "Markdown highlighting checks passed.\n";
