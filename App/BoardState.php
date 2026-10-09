<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Keep Board screen selections independent from List filters. */
class BoardState {

  public static function load(): array {
    return array_replace(['projectKey' => '', 'boardId' => '', 'sprintId' => ''], AppData::loadJson('board-state.json'));
  }

  public static function save(array $state): bool {
    return AppData::saveJson('board-state.json', [
      'projectKey' => (string)($state['projectKey'] ?? ''),
      'boardId' => (string)($state['boardId'] ?? ''),
      'sprintId' => (string)($state['sprintId'] ?? ''),
    ]);
  }
}
