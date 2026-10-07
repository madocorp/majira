<?php

namespace MAJIRA\App;

use SPTK\Core\AppData;

/** Loads and saves the local Jira connection settings. */
class Settings {

  public static function load(): array {
    return AppData::loadJson('settings.json');
  }

  public static function save(array $settings): bool {
    return AppData::saveJson('settings.json', $settings);
  }

  public static function file(): string {
    return AppData::file('settings.json');
  }

  public static function isConfigured(?array $settings = null): bool {
    $settings ??= self::load();
    return trim((string)($settings['site'] ?? '')) !== ''
      && trim((string)($settings['email'] ?? '')) !== ''
      && trim((string)($settings['apiToken'] ?? '')) !== '';
  }

}
