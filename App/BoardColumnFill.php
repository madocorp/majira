<?php

namespace MAJIRA\App;

use SPTK\Core\{Color, Style, Widget};
use SPTK\Events\WidgetEventEmitter;
use SPTK\Rendering\GridWriter;

/** Paint unused column rows with the same dimming as unselected ticket tiles. */
final class BoardColumnFill extends Widget {

  use WidgetEventEmitter;

  public function __construct(private readonly Style $style = new Style()) {
  }

  public function background(): Color {
    return $this->style->background;
  }

  public function paint(GridWriter $writer): void {
    $writer->fill($this->style->foreground, $this->style->background);
  }

  public function canActivate(): bool {
    return false;
  }

  public function handleInput(mixed $event): bool {
    return false;
  }

}
