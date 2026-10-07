<?php

namespace MAJIRA\App;

use SPTK\Core\{Color, Style, Widget};
use SPTK\Events\WidgetEventEmitter;
use SPTK\Rendering\GridWriter;

/** A two-row filter heading and its selected value. */
final class FilterSummary extends Widget {

  use WidgetEventEmitter;

  private string $value = '-';

  public function __construct(private readonly string $title, private readonly Style $style = new Style()) {
  }

  public function setValue(string $value): void {
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
      throw new \InvalidArgumentException('Filter summary must be printable single-line UTF-8 text.');
    }
    $this->value = $value;
    $this->emit('change');
  }

  public function value(): string {
    return $this->value;
  }

  public function background(): Color {
    return $this->style->background;
  }

  public function paint(GridWriter $writer): void {
    $writer->fill($this->style->foreground, $this->style->background);
    if ($writer->width() > 0 && $writer->height() > 0) {
      $writer->write(0, 0, $this->title, $this->style->highlight, $this->style->background);
    }
    if ($writer->width() > 0 && $writer->height() > 1) {
      $writer->write(0, 1, $this->value, $this->style->foreground, $this->style->background);
    }
  }

  public function canActivate(): bool {
    return false;
  }

  public function handleInput(mixed $event): bool {
    return false;
  }

  public function preferredHeight(): ?int {
    return 2;
  }

}
