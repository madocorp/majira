<?php

namespace MAJIRA\App;

use SPTK\Core\Style;
use SPTK\Widgets\Image\Image;
use SPTK\Widgets\Table\Table;
use SPTK\Widgets\Text\Text;

/** Build a read-only viewer for the attachment formats MaJira can display. */
final class AttachmentPreview {

  public static function kind(array $attachment): ?string {
    $name = strtolower((string)($attachment['filename'] ?? ''));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = strtolower(trim(explode(';', (string)($attachment['mimeType'] ?? ''))[0]));
    if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true)
      || in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'], true)) {
      return 'image';
    }
    if ($extension === 'csv' || ($extension === '' && $mime === 'text/csv')) {
      return 'csv';
    }
    if ($extension === 'tsv' || ($extension === '' && $mime === 'text/tab-separated-values')) {
      return 'tsv';
    }
    if (in_array($extension, ['txt', 'text', 'sql', 'md', 'log', 'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'sh', 'php', 'js', 'css', 'html', 'htm', 'py'], true)
      || ($extension === '' && str_starts_with($mime, 'text/'))) {
      return 'text';
    }
    return null;
  }

  public static function widget(string $kind, string $path, string $name): Image|Text|Table {
    if ($kind === 'image') {
      $image = new Image($path, fit: 'contain', title: $name);
      $image->source(); // Report corrupt or unsupported images before changing screens.
      return $image;
    }
    if ($kind === 'text') {
      $contents = file_get_contents($path);
      if ($contents === false) {
        throw new \RuntimeException('Could not read attachment.');
      }
      return new Text(self::utf8($contents), new Style(), false, title: $name);
    }
    if ($kind === 'csv' || $kind === 'tsv') {
      [$header, $rows] = self::delimited($path, $kind === 'csv' ? ',' : "\t");
      return new Table($header, $rows, title: $name);
    }
    throw new \InvalidArgumentException('Unsupported attachment type.');
  }

  /** Parse actual CSV or TSV quoting, including embedded delimiters and newlines. */
  private static function delimited(string $path, string $delimiter): array {
    $file = fopen($path, 'rb');
    if ($file === false) {
      throw new \RuntimeException('Could not read attachment.');
    }
    try {
      $records = [];
      while (($record = fgetcsv($file, 0, $delimiter, '"', '')) !== false) {
        $records[] = array_map(static fn(?string $field): string => self::utf8($field ?? ''), $record);
      }
    } finally {
      fclose($file);
    }
    if ($records === []) {
      return [[], []];
    }
    $records[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $records[0][0]);
    return [array_shift($records), $records];
  }

  private static function utf8(string $value): string {
    if (!mb_check_encoding($value, 'UTF-8')) {
      throw new \RuntimeException('Attachment is not valid UTF-8 text.');
    }
    return $value;
  }

}
