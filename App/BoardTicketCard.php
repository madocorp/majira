<?php

namespace MAJIRA\App;

use SPTK\Core\{Color, Style, Widget};
use SPTK\Events\WidgetEventEmitter;
use SPTK\Rendering\{GridWriter, TextMetrics};

/** A four-row, focusable sprint issue card. */
final class BoardTicketCard extends Widget {

  use WidgetEventEmitter;

  public const HEIGHT = 4;

  /** Priority marks from highest to lowest; LiberationMono draws each in one cell. */
  private const PRIORITY_MARKS = [
    'show-stopper' => '‼', 'blocker' => '‼', 'highest' => '‼',
    'critical' => '▲', 'urgent' => '▲',
    'high' => '↑', 'major' => '↑',
    'medium' => '=', 'normal' => '=', 'standart' => '=',
    'low' => '↓', 'minor' => '↓',
    'lowest' => '▼', 'trivial' => '▼',
  ];

  public function __construct(private readonly string $key, private readonly string $summary, private readonly string $assignee, private readonly string $type = '', private readonly string $badge = '', private readonly Style $style = new Style()) {
  }

  /** Build the story point and priority mark shown beside the assignee, e.g. "2=". */
  public static function badge(mixed $points, string $priority): string {
    $estimate = is_numeric($points) ? rtrim(rtrim(number_format((float)$points, 2, '.', ''), '0'), '.') : '';
    $mark = $priority === '' ? '' : (self::PRIORITY_MARKS[mb_strtolower(trim($priority))] ?? '?');
    return $estimate . $mark;
  }

  public function key(): string {
    return $this->key;
  }

  public function background(): Color {
    return $this->style->background;
  }

  /** Paint the key and type, two wrapped summary rows, and assignee with the badge. */
  public function paint(GridWriter $writer): void {
    $background = $this->background();
    $writer->fill($this->style->foreground, $background);
    $width = max(0, $writer->width() - 2);
    if ($width === 0) {
      return;
    }
    if ($this->key !== '') {
      $writer->write(1, 0, '#' . $this->key, $this->style->highlight, $background);
      $keyWidth = min($width, TextMetrics::width('#' . $this->key));
      $typeSpace = $width - $keyWidth - 1;
      if ($this->type !== '' && $typeSpace >= 3) {
        $type = $this->type;
        if (TextMetrics::width($type) > $typeSpace) {
          $type = TextMetrics::slice($type, 0, TextMetrics::index($type, $typeSpace - 1)) . '…';
        }
        $writer->write($writer->width() - 1 - TextMetrics::width($type), 0, $type, $this->style->selected, $background);
      }
    }
    $lines = self::wrap($this->summary, $width, 2);
    foreach ($lines as $index => $line) {
      if ($index + 1 < $writer->height()) {
        $writer->write(1, $index + 1, $line, $this->style->foreground, $background);
      }
    }
    if ($writer->height() > 3) {
      $assignee = $this->assignee !== '' ? $this->assignee : 'Unassigned';
      $badgeWidth = TextMetrics::width($this->badge);
      $space = $badgeWidth === 0 ? $width : $width - $badgeWidth - 1;
      if ($space >= 1 && TextMetrics::width($assignee) > $space) {
        $assignee = TextMetrics::slice($assignee, 0, TextMetrics::index($assignee, $space - 1)) . '…';
      }
      if ($space >= 1) {
        $writer->write(1, 3, $assignee, $this->style->selected, $background);
      }
      if ($badgeWidth > 0 && $badgeWidth <= $width) {
        $writer->write($writer->width() - 1 - $badgeWidth, 3, $this->badge, $this->style->highlight, $background);
      }
    }
  }

  /** Keep a title within the card width without splitting grapheme clusters. */
  private static function wrap(string $text, int $width, int $rows): array {
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
      while (TextMetrics::width($word) > $width) {
        if ($line !== '') {
          $lines[] = $line;
          $line = '';
        }
        $part = '';
        foreach (TextMetrics::glyphs($word) as $glyph) {
          if (TextMetrics::width($part . $glyph) > $width) {
            break;
          }
          $part .= $glyph;
        }
        if ($part === '') {
          break;
        }
        $lines[] = $part;
        $word = TextMetrics::slice($word, TextMetrics::length($part));
      }
      if ($word === '') {
        continue;
      }
      $candidate = $line === '' ? $word : $line . ' ' . $word;
      if (TextMetrics::width($candidate) > $width) {
        $lines[] = $line;
        $line = $word;
      } else {
        $line = $candidate;
      }
    }
    if ($line !== '') {
      $lines[] = $line;
    }
    return array_slice($lines, 0, $rows);
  }

  public function canActivate(): bool {
    return $this->key !== '';
  }

  public function handleInput(mixed $event): bool {
    return false;
  }

  public function preferredHeight(): ?int {
    return self::HEIGHT;
  }

  protected function defaultTip(bool $active): string {
    return $this->key === '' ? 'No issues in this column.' : 'Return opens ' . $this->key . '; arrows move between cards and columns.';
  }

}
