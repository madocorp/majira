<?php

namespace MAJIRA\Highlighting;

use SPTK\Rendering\TextMetrics;

/** Highlight the Markdown syntax retained by Jira description and comment text. */
final class MarkdownHighlighter extends RuleSyntaxHighlighter {

  public function __construct() {
    parent::__construct([
      ['regex' => '/^>>> .+/u', 'style' => 'header', 'first' => true],
      ['regex' => '/^#{1,6} .*/u', 'style' => 'heading', 'first' => true],
      ['regex' => '/^(?:[-*+•]|\d+[.)])\s+/u', 'style' => 'list', 'first' => true],
      ['regex' => '/^\*\*[^*]+\*\*/u', 'style' => 'strong'],
      ['regex' => '/^`[^`]+`/u', 'style' => 'code'],
      ['regex' => '~^\[[^\]\n]+\]\((?:https?://|mailto:)[^\s)]+\)~u', 'style' => 'link'],
      ['regex' => '~^<(?:https?://|mailto:)[^\s<>]+>~u', 'style' => 'link'],
      ['regex' => '~^<#[A-Z][A-Z0-9_]*-\d+>~u', 'style' => 'link'],
      ['regex' => '/^>\s+/u', 'style' => 'quote', 'first' => true],
    ], [
      'header' => ['fg' => '#ffffff', 'bg' => '#982461', 'fillRow' => true],
      'heading' => ['fg' => '#001f33', 'bg' => '#82b4d8'],
      'list' => ['fg' => '#00ffff'],
      'strong' => ['fg' => '#3d2a00', 'bg' => '#d2bf64'],
      'code' => ['fg' => '#004f6f', 'bg' => '#a8d8e4'],
      'link' => ['fg' => '#87d7ff'],
      'code-block' => ['fg' => '#003f66', 'bg' => '#b7d7e8', 'fillRow' => true],
      'quote' => ['fg' => '#82b4d8'],
    ]);
  }

  /** Carry fenced code context across lines while keeping offsets line-local. */
  public function highlight(array $lines): array {
    $result = [];
    $inCode = false;
    foreach ($lines as $line) {
      $fence = preg_match('/^\s*```/u', $line) === 1;
      if ($fence || $inCode) {
        $result[] = [['start' => 0, 'length' => TextMetrics::length($line), 'style' => 'code-block']];
      } else {
        $result[] = $this->highlightLine($line);
      }
      if ($fence) {
        $inCode = !$inCode;
      }
    }
    return $result;
  }

}
