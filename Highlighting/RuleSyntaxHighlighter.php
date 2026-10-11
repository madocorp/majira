<?php

namespace MAJIRA\Highlighting;

use SPTK\Core\SyntaxHighlighter;
use SPTK\Rendering\TextMetrics;

/** Apply ordered literal and regular-expression rules to document lines. */
class RuleSyntaxHighlighter implements SyntaxHighlighter {

  public function __construct(
    protected array $rules = [],
    protected array $colors = [
      'keyword' => ['fg' => '#ffffff', 'bg' => '#0000aa'],
      'string' => ['fg' => '#00ffff', 'bg' => '#0000aa'],
      'number' => ['fg' => '#ffff00', 'bg' => '#0000aa'],
      'comment' => ['fg' => '#777777', 'bg' => '#0000aa'],
      'operator' => ['fg' => '#ffffff', 'bg' => '#0000aa'],
    ],
  ) {
  }

  public static function fromRules(array $rules, ?array $colors = null): self {
    return $colors === null ? new self($rules) : new self($rules, $colors);
  }

  public function highlight(array $lines): array {
    return array_map($this->highlightLine(...), $lines);
  }

  public function styleColors(): array {
    return $this->colors;
  }

  /** Preserve source grapheme offsets for wrapped and scrolled text. */
  protected function highlightLine(string $line): array {
    $tokens = [];
    $glyphs = TextMetrics::glyphs($line);
    $length = count($glyphs);
    $byteOffset = 0;
    for ($offset = 0; $offset < $length;) {
      $tail = substr($line, $byteOffset);
      $match = $this->match($tail, $offset === 0);
      if ($match === null) {
        $match = [1, 'plain'];
      }
      [$count, $style] = $match;
      $last = count($tokens) - 1;
      if ($last >= 0 && $tokens[$last]['style'] === $style) {
        $tokens[$last]['length'] += $count;
      } else {
        $tokens[] = ['start' => $offset, 'length' => $count, 'style' => $style];
      }
      $offset += $count;
      $byteOffset += strlen(implode('', array_slice($glyphs, $offset - $count, $count)));
    }
    return $tokens;
  }

  /** Return the first nonempty rule match at the start of the remaining line. */
  private function match(string $tail, bool $first): ?array {
    foreach ($this->rules as $rule) {
      if (($rule['first'] ?? false) && !$first) {
        continue;
      }
      $value = null;
      if (isset($rule['literal'])) {
        $literal = (string)$rule['literal'];
        if ($literal !== '' && str_starts_with($tail, $literal)) {
          $value = $literal;
        }
      } else if (isset($rule['regex']) && preg_match($rule['regex'], $tail, $matches, PREG_OFFSET_CAPTURE) === 1) {
        $candidate = $matches[1] ?? $matches[0];
        if ($candidate[1] === 0 && $candidate[0] !== '') {
          $value = $candidate[0];
        }
      }
      if ($value !== null) {
        return [TextMetrics::length($value), (string)($rule['style'] ?? $rule['type'] ?? 'plain')];
      }
    }
    return null;
  }

}
