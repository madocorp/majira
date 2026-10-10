<?php

namespace MAJIRA\App;

use SPTK\Core\{Color, Style, Widget};
use SPTK\Events\WidgetEventEmitter;
use SPTK\Rendering\{GridWriter, TextMetrics};

/** A four-row, focusable sprint issue card. */
final class BoardTicketCard extends Widget {

  use WidgetEventEmitter;

  public const HEIGHT = 4;

  public function __construct(private readonly string $key, private readonly string $summary, private readonly string $assignee, private readonly string $type = '', private readonly Style $style = new Style()) {
  }

  public function key(): string {
    return $this->key;
  }

  public function background(): Color {
    return $this->style->background;
  }

  /** Paint the key and type, two wrapped summary rows, and assignee name. */
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
      $writer->write(1, 3, $this->assignee !== '' ? $this->assignee : 'Unassigned', $this->style->selected, $background);
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
