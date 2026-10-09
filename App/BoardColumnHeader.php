<?php

namespace MAJIRA\App;

use SPTK\Core\{Color, Style, Widget};
use SPTK\Events\WidgetEventEmitter;
use SPTK\Layout\LayoutLeaf;
use SPTK\Rendering\{GridWriter, TextMetrics};

/** Shows a board column title, issue count, and cards hidden above or below. */
final class BoardColumnHeader extends Widget {

  use WidgetEventEmitter;

  /** @param LayoutLeaf[] $cards */
  public function __construct(private readonly string $title, private readonly int $issueCount, private readonly array $cards, private readonly Style $style = new Style()) {
  }

  public function background(): Color {
    return $this->style->background;
  }

  public function paint(GridWriter $writer): void {
    $writer->fill($this->style->highlight, $this->style->background);
    if ($writer->height() === 0 || $writer->width() === 0) {
      return;
    }
    $above = 0;
    $below = 0;
    foreach ($this->cards as $card) {
      $grid = $card->grid();
      $visible = $card->visibleGrid();
      if ($visible->height > 0) {
        continue;
      }
      if ($grid->y < $visible->y) {
        $above++;
      } else {
        $below++;
      }
    }
    $suffix = ($above > 0 ? ' ' . $above . '▲' : '') . ($below > 0 ? ' ' . $below . '▼' : '');
    $label = $this->title . ' (' . $this->issueCount . ')';
    $available = max(0, $writer->width() - TextMetrics::width($suffix));
    $clipped = '';
    foreach (TextMetrics::glyphs($label) as $glyph) {
      if (TextMetrics::width($clipped . $glyph) > $available) {
        break;
      }
      $clipped .= $glyph;
    }
    $writer->write(0, 0, $clipped . $suffix, $this->style->highlight, $this->style->background);
  }

  public function canActivate(): bool {
    return false;
  }

  public function handleInput(mixed $event): bool {
    return false;
  }

  public function preferredHeight(): ?int {
    return 1;
  }

}
