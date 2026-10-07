<?php

namespace MAJIRA\Jira;

/** Flattens Atlassian Document Format (issue description/comment bodies) into markdown-like plain text. */
class Adf {

  public static function toText(mixed $node): string {
    if (!is_array($node)) {
      return '';
    }
    return self::normalizeBlankLines(self::normalizeMarkdownArtifacts(trim(self::renderNode($node), "\r\n")));
  }

  public static function fromMarkdown(string $text): array {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($text)));
    $content = [];
    for ($i = 0; $i < count($lines); $i++) {
      $line = $lines[$i];
      if (trim($line) === '') {
        continue;
      }
      if (preg_match('/^```([A-Za-z0-9_-]+)?\s*$/u', rtrim($line), $matches)) {
        $language = (string)($matches[1] ?? '');
        $code = [];
        while (++$i < count($lines)) {
          if (preg_match('/^```\s*$/u', rtrim($lines[$i]))) {
            break;
          }
          $code[] = $lines[$i];
        }
        $content[] = self::codeBlockNode(implode("\n", $code), $language);
        continue;
      }
      if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $matches)) {
        $content[] = [
          'type' => 'heading',
          'attrs' => ['level' => strlen($matches[1])],
          'content' => self::inlineNodes($matches[2]),
        ];
        continue;
      }
      if (preg_match('/^- \[([ xX])\]\s+(.+)$/u', $line)) {
        $items = [];
        do {
          preg_match('/^- \[([ xX])\]\s+(.+)$/u', $lines[$i], $matches);
          $items[] = [
            'type' => 'taskItem',
            'attrs' => ['state' => trim((string)$matches[1]) === '' ? 'TODO' : 'DONE'],
            'content' => self::inlineNodes($matches[2]),
          ];
          $i++;
        } while ($i < count($lines) && preg_match('/^- \[([ xX])\]\s+(.+)$/u', $lines[$i]));
        $i--;
        $content[] = [
          'type' => 'taskList',
          'content' => $items,
        ];
        continue;
      }
      if (preg_match('/^(?:-|•)\s+(.+)$/u', $line)) {
        $items = [];
        do {
          preg_match('/^(?:-|•)\s+(.+)$/u', $lines[$i], $matches);
          $items[] = [
            'type' => 'listItem',
            'content' => [self::paragraphNode($matches[1])],
          ];
          $i++;
        } while ($i < count($lines) && preg_match('/^(?:-|•)\s+(.+)$/u', $lines[$i]));
        $i--;
        $content[] = [
          'type' => 'bulletList',
          'content' => $items,
        ];
        continue;
      }
      $paragraph = [$line];
      while ($i + 1 < count($lines) && trim($lines[$i + 1]) !== ''
        && !preg_match('/^```/u', $lines[$i + 1])
        && !preg_match('/^(#{1,6})\s+.+$/u', $lines[$i + 1])
        && !preg_match('/^- \[[ xX]\]\s+.+$/u', $lines[$i + 1])
        && !preg_match('/^(?:-|•)\s+.+$/u', $lines[$i + 1])) {
        $paragraph[] = $lines[++$i];
      }
      $content[] = self::multilineParagraphNode($paragraph);
    }
    if ($content === []) {
      $content[] = self::paragraphNode('');
    }
    return [
      'type' => 'doc',
      'version' => 1,
      'content' => $content,
    ];
  }

  private static function paragraphNode(string $text): array {
    return [
      'type' => 'paragraph',
      'content' => self::inlineNodes($text),
    ];
  }

  private static function multilineParagraphNode(array $lines): array {
    $content = [];
    foreach ($lines as $index => $line) {
      if ($index > 0) {
        $content[] = ['type' => 'hardBreak'];
      }
      array_push($content, ...self::inlineNodes($line));
    }
    return [
      'type' => 'paragraph',
      'content' => $content,
    ];
  }

  private static function codeBlockNode(string $text, string $language = ''): array {
    $node = [
      'type' => 'codeBlock',
      'content' => [['type' => 'text', 'text' => $text]],
    ];
    if ($language !== '') {
      $node['attrs'] = ['language' => $language];
    }
    return $node;
  }

  private static function inlineNodes(string $text): array {
    if ($text === '') {
      return [];
    }
    $nodes = [];
    while ($text !== '') {
      $bold = strpos($text, '**');
      $code = strpos($text, '`');
      $next = self::nearestInlineMarker($bold, $code);
      if ($next === false) {
        self::appendTextNode($nodes, $text);
        break;
      }
      if ($next > 0) {
        self::appendTextNode($nodes, substr($text, 0, $next));
        $text = substr($text, $next);
      }
      if (str_starts_with($text, '**')) {
        $end = strpos($text, '**', 2);
        if ($end === false) {
          self::appendTextNode($nodes, '**');
          $text = substr($text, 2);
          continue;
        }
        self::appendTextNode($nodes, substr($text, 2, $end - 2), [['type' => 'strong']]);
        $text = substr($text, $end + 2);
        continue;
      }
      $end = strpos($text, '`', 1);
      if ($end === false) {
        self::appendTextNode($nodes, '`');
        $text = substr($text, 1);
        continue;
      }
      self::appendTextNode($nodes, substr($text, 1, $end - 1), [['type' => 'code']]);
      $text = substr($text, $end + 1);
    }
    return $nodes;
  }

  private static function nearestInlineMarker(int|false $bold, int|false $code): int|false {
    if ($bold === false) {
      return $code;
    }
    if ($code === false) {
      return $bold;
    }
    return min($bold, $code);
  }

  private static function appendTextNode(array &$nodes, string $text, array $marks = []): void {
    if ($text === '') {
      return;
    }
    $node = ['type' => 'text', 'text' => $text];
    if ($marks !== []) {
      $node['marks'] = $marks;
    }
    $nodes[] = $node;
  }

  private static function renderNode(array $node): string {
    switch ($node['type'] ?? '') {
      case 'text':
        return self::renderText($node);
      case 'hardBreak':
        return "\n";
      case 'heading':
        $level = max(1, (int)($node['attrs']['level'] ?? 1));
        return str_repeat('#', $level) . ' ' . self::renderChildren($node) . "\n\n";
      case 'paragraph':
        return self::renderChildren($node) . "\n\n";
      case 'taskList':
        return self::renderChildren($node) . "\n";
      case 'taskItem':
      case 'blockTaskItem':
        return self::renderTaskItem($node);
      case 'bulletList':
        return self::renderChildren($node) . "\n";
      case 'orderedList':
        return self::renderOrderedList($node) . "\n";
      case 'listItem':
        return '• ' . trim(self::renderChildren($node)) . "\n";
      case 'codeBlock':
        return self::renderFencedCodeBlock(self::renderCodeBlock($node));
      case 'rule':
        return str_repeat('-', 40) . "\n\n";
      case 'mention':
        return (string)($node['attrs']['text'] ?? $node['attrs']['displayName'] ?? '');
      case 'emoji':
        return (string)($node['attrs']['shortName'] ?? $node['attrs']['text'] ?? '');
      case 'inlineCard':
        return (string)($node['attrs']['url'] ?? '');
      case 'status':
        return (string)($node['attrs']['text'] ?? '');
      default:
        return self::renderChildren($node);
    }
  }

  private static function renderTaskItem(array $node): string {
    $state = strtolower((string)($node['attrs']['state'] ?? $node['attrs']['checked'] ?? ''));
    $marker = in_array($state, ['done', 'checked', 'complete', 'completed', 'true', '1'], true) ? '[x]' : '[ ]';
    return '- ' . $marker . ' ' . trim(self::renderChildren($node)) . "\n";
  }

  private static function renderOrderedList(array $node): string {
    $text = '';
    $index = max(1, (int)($node['attrs']['order'] ?? 1));
    foreach ($node['content'] ?? [] as $child) {
      if (!is_array($child)) {
        continue;
      }
      if (($child['type'] ?? '') !== 'listItem') {
        $text .= self::renderNode($child);
        continue;
      }
      $text .= $index . '. ' . trim(self::renderChildren($child)) . "\n";
      $index++;
    }
    return $text;
  }

  /** Wraps text-node marks in markdown-style delimiters for the comment tokenizer. */
  private static function renderText(array $node): string {
    $text = (string)($node['text'] ?? '');
    foreach ($node['marks'] ?? [] as $mark) {
      if (!is_array($mark)) {
        continue;
      }
      switch ($mark['type'] ?? '') {
        case 'strong':
          $text = self::wrapMarkedText($text, '**', '**');
          break;
        case 'code':
          $text = "`{$text}`";
          break;
      }
    }
    return $text;
  }

  private static function renderChildren(array $node): string {
    $text = '';
    $children = $node['content'] ?? [];
    for ($i = 0; $i < count($children); $i++) {
      $child = $children[$i];
      if (!is_array($child)) {
        continue;
      }
      $codeText = self::codeOnlyParagraphText($child);
      if ($codeText !== false) {
        $codeLines = [$codeText];
        $start = $i;
        while (isset($children[$i + 1]) && is_array($children[$i + 1])) {
          $nextCodeText = self::codeOnlyParagraphText($children[$i + 1]);
          if ($nextCodeText === false) {
            break;
          }
          $codeLines[] = $nextCodeText;
          $i++;
        }
        if (count($codeLines) > 1) {
          $text .= self::renderFencedCodeBlock(trim(implode("\n", $codeLines)));
          continue;
        }
        $i = $start;
      }
      $text .= self::renderNode($child);
    }
    return $text;
  }

  private static function renderCodeBlock(array $node): string {
    return trim(self::renderCodeChildren($node));
  }

  private static function renderFencedCodeBlock(string $code): string {
    $lines = $code === '' ? [] : explode("\n", $code);
    $fence = '```';
    $block = array_merge([$fence], $lines, [$fence]);
    return implode("\n", $block) . "\n\n";
  }

  private static function codeOnlyParagraphText(array $node): string|false {
    if (($node['type'] ?? '') !== 'paragraph') {
      return false;
    }
    $text = '';
    foreach ($node['content'] ?? [] as $child) {
      if (!is_array($child)) {
        continue;
      }
      if (($child['type'] ?? '') === 'hardBreak') {
        $text .= "\n";
        continue;
      }
      if (($child['type'] ?? '') !== 'text' || !self::hasMark($child, 'code')) {
        return false;
      }
      $text .= (string)($child['text'] ?? '');
    }
    return trim($text) === '' ? false : $text;
  }

  private static function hasMark(array $node, string $type): bool {
    foreach ($node['marks'] ?? [] as $mark) {
      if (is_array($mark) && ($mark['type'] ?? '') === $type) {
        return true;
      }
    }
    return false;
  }

  private static function renderCodeChildren(array $node): string {
    $text = '';
    foreach ($node['content'] ?? [] as $child) {
      if (is_array($child)) {
        $text .= self::renderCodeNode($child);
      }
    }
    return $text;
  }

  private static function renderCodeNode(array $node): string {
    switch ($node['type'] ?? '') {
      case 'text':
        return (string)($node['text'] ?? '');
      case 'hardBreak':
        return "\n";
      case 'paragraph':
        return self::renderCodeChildren($node) . "\n";
      default:
        return self::renderCodeChildren($node);
    }
  }

  private static function normalizeBlankLines(string $text): string {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $normalized = [];
    $inCode = false;
    $blank = 0;
    foreach ($lines as $line) {
      $isFence = str_starts_with(rtrim($line), '```');
      if ($isFence) {
        $inCode = !$inCode;
      }
      if (!$inCode && trim($line) === '') {
        $blank++;
        if ($blank > 1) {
          continue;
        }
      } else {
        $blank = 0;
      }
      $normalized[] = $line;
    }
    return implode("\n", $normalized);
  }

  private static function normalizeMarkdownArtifacts(string $text): string {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $normalized = [];
    $inCode = false;
    foreach ($lines as $line) {
      $isFence = str_starts_with(rtrim($line), '```');
      if ($isFence) {
        $inCode = !$inCode;
        $normalized[] = $line;
        continue;
      }
      if (!$inCode && preg_match('/^#+\s*$/u', $line)) {
        continue;
      }
      $normalized[] = $line;
    }
    return implode("\n", $normalized);
  }

  private static function wrapMarkedText(string $text, string $start, string $end): string {
    if (!preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $matches)) {
      return $start . $text . $end;
    }
    if ($matches[2] === '') {
      return $text;
    }
    return $matches[1] . $start . $matches[2] . $end . $matches[3];
  }

}
