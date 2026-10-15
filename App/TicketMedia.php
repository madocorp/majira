<?php

namespace MAJIRA\App;

use MAJIRA\Jira\Adf;
use SPTK\Core\AppData;

/** Shows embedded attachments as <@name> lines and turns <@row>, <@name>, or <@uuid> lines into Jira media. */
class TicketMedia {

  private const FILE = 'media-ids.json';
  private const TOKEN = '/^<@([^<>\n]+)>$/u';
  private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

  private array $attachments = [];

  public function __construct(array $issue, private JiraData $data) {
    foreach ((array)($issue['fields']['attachment'] ?? []) as $attachment) {
      if (is_array($attachment) && (string)($attachment['id'] ?? '') !== '') {
        $this->attachments[] = $attachment;
      }
    }
  }

  /** Render ADF with known media ids replaced by attachment names. */
  public function toText(mixed $node): string {
    $names = $this->names();
    return preg_replace_callback('/^<@([0-9a-f-]{36})>$/mu', static fn(array $match): string => isset($names[$match[1]]) ? '<@' . $names[$match[1]] . '>' : $match[0], Adf::toText($node)) ?? '';
  }

  /** Report whether the text has media ids that an uncached attachment may explain. */
  public function needsLookup(string $text): bool {
    preg_match_all('/^<@([0-9a-f-]{36})>$/mu', $text, $matches);
    return $matches[1] !== [] && $this->uncached() !== [];
  }

  /** Look up uncached attachments until every media id in the text is known. */
  public function resolve(string $text): void {
    preg_match_all('/^<@([0-9a-f-]{36})>$/mu', $text, $matches);
    $missing = array_diff($matches[1], array_keys($this->names()));
    foreach ($this->uncached() as $attachment) {
      if ($missing === []) {
        return;
      }
      $missing = array_diff($missing, [$this->lookup((string)$attachment['id'])]);
    }
  }

  /** Return the attachment of the first <@row>, <@name>, or known <@uuid> token in a line. */
  public function linked(string $line): ?array {
    if (preg_match('/<@([^<>\n]+)>/u', $line, $match) !== 1) {
      return null;
    }
    $id = array_search($match[1], $this->cache(), true);
    foreach ($this->attachments as $attachment) {
      if ($id !== false && (string)$attachment['id'] === (string)$id) {
        return $attachment;
      }
    }
    try {
      return $this->attachment($match[1]);
    } catch (\InvalidArgumentException) {
      return null;
    }
  }

  /** Build ADF and replace whole-line media tokens with media blocks. */
  public function fromMarkdown(string $text): array {
    $document = Adf::fromMarkdown($text);
    $content = [];
    foreach ($document['content'] as $block) {
      array_push($content, ...(($block['type'] ?? '') === 'paragraph' ? $this->splitParagraph($block) : [$block]));
    }
    $document['content'] = $content === [] ? [['type' => 'paragraph', 'content' => []]] : $content;
    return $document;
  }

  /** Split a paragraph at hard breaks so token lines become media blocks. */
  private function splitParagraph(array $paragraph): array {
    $lines = [[]];
    foreach ((array)($paragraph['content'] ?? []) as $node) {
      if (($node['type'] ?? '') === 'hardBreak') {
        $lines[] = [];
      } else {
        $lines[count($lines) - 1][] = $node;
      }
    }
    $blocks = [];
    $text = [];
    foreach ($lines as $line) {
      $token = count($line) === 1 && ($line[0]['type'] ?? '') === 'text' && empty($line[0]['marks'])
        && preg_match(self::TOKEN, trim((string)$line[0]['text']), $match) === 1 ? $match[1] : null;
      if ($token === null) {
        $text[] = $line;
        continue;
      }
      if ($text !== []) {
        $blocks[] = $this->paragraph($text);
        $text = [];
      }
      $blocks[] = $this->mediaBlock($token);
    }
    if ($text !== []) {
      $blocks[] = $this->paragraph($text);
    }
    return $blocks;
  }

  /** Join inline lines back into one paragraph with hard breaks. */
  private function paragraph(array $lines): array {
    $content = [];
    foreach ($lines as $index => $line) {
      if ($index > 0) {
        $content[] = ['type' => 'hardBreak'];
      }
      array_push($content, ...$line);
    }
    return ['type' => 'paragraph', 'content' => $content];
  }

  /** Build an image block or a file card for a row number, file name, or media id. */
  private function mediaBlock(string $token): array {
    $attachment = $this->attachment($token);
    $id = $attachment === null ? $token : ($this->cache()[(string)$attachment['id']] ?? $this->lookup((string)$attachment['id']));
    $media = ['type' => 'media', 'attrs' => ['type' => 'file', 'id' => $id, 'collection' => '']];
    if (str_starts_with((string)($attachment['mimeType'] ?? ''), 'image/')) {
      return ['type' => 'mediaSingle', 'attrs' => ['layout' => 'center'], 'content' => [$media]];
    }
    return ['type' => 'mediaGroup', 'content' => [$media]];
  }

  /** Find the attachment a token names; a raw media id needs none. */
  private function attachment(string $token): ?array {
    if (ctype_digit($token)) {
      $attachment = $this->attachments[(int)$token - 1] ?? null;
      if ($attachment === null) {
        throw new \InvalidArgumentException("No attachment in row {$token}.");
      }
      return $attachment;
    }
    $named = array_values(array_filter($this->attachments, static fn(array $item): bool => (string)($item['filename'] ?? '') === $token));
    if (count($named) > 1) {
      throw new \InvalidArgumentException("Several attachments are named {$token}; use its row number.");
    }
    if ($named === [] && preg_match(self::UUID, $token) !== 1) {
      throw new \InvalidArgumentException("No attachment named {$token}.");
    }
    return $named[0] ?? null;
  }

  /** Map known media ids to attachment names. */
  private function names(): array {
    $cache = $this->cache();
    $names = [];
    foreach ($this->attachments as $attachment) {
      $id = $cache[(string)$attachment['id']] ?? null;
      if ($id !== null) {
        $names[$id] = (string)($attachment['filename'] ?? $attachment['id']);
      }
    }
    return $names;
  }

  private function uncached(): array {
    $cache = $this->cache();
    return array_values(array_filter($this->attachments, static fn(array $item): bool => !isset($cache[(string)$item['id']])));
  }

  /** Ask Jira for one attachment's media id and remember it; the pair never changes. */
  private function lookup(string $attachmentId): string {
    $id = $this->data->client()->attachmentMediaId($attachmentId);
    $cache = $this->cache();
    $cache[$attachmentId] = $id;
    AppData::saveJson(self::FILE, $cache);
    return $id;
  }

  private function cache(): array {
    return AppData::loadJson(self::FILE);
  }

}
