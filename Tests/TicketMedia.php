<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{JiraData, TicketMedia};
use MAJIRA\Jira\Client;

/** Answer media id lookups from a fixed table and count them. */
final class MediaClient extends Client {

  public array $lookups = [];

  public function __construct(private array $ids) {
    parent::__construct('https://example.invalid', 'test@example.invalid', 'token');
  }

  public function attachmentMediaId(string $attachmentId): string {
    $this->lookups[] = $attachmentId;
    return $this->ids[$attachmentId];
  }

}

/** Serve the fake client without saved settings. */
final class MediaData extends JiraData {

  public function __construct(private MediaClient $fake) {
  }

  public function client(): Client {
    return $this->fake;
  }

}

/** Fail with a readable message. */
function mediaCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Expect the text to be rejected on save. */
function mediaRejects(TicketMedia $media, string $text, string $expected): void {
  try {
    $media->fromMarkdown($text);
  } catch (InvalidArgumentException $error) {
    mediaCheck(str_contains($error->getMessage(), $expected), 'Unexpected error: ' . $error->getMessage());
    return;
  }
  throw new RuntimeException('Invalid token was accepted: ' . $text);
}

$testHome = sys_get_temp_dir() . '/majira-test-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
$a = '11111111-1111-1111-1111-111111111111';
$b = '22222222-2222-2222-2222-222222222222';
$c = '33333333-3333-3333-3333-333333333333';
$client = new MediaClient(['1' => $a, '2' => $b, '3' => $c]);
$issue = ['key' => 'AP-1', 'fields' => ['attachment' => [
  ['id' => '1', 'filename' => 'notes.txt', 'mimeType' => 'text/plain'],
  ['id' => '2', 'filename' => 'shot.png', 'mimeType' => 'image/png'],
  ['id' => '3', 'filename' => 'my_file.txt', 'mimeType' => 'text/plain'],
]]];
$media = new TicketMedia($issue, new MediaData($client));
$body = ['type' => 'doc', 'content' => [
  ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'See below.']]],
  ['type' => 'mediaSingle', 'content' => [['type' => 'media', 'attrs' => ['type' => 'file', 'id' => $b, 'collection' => '']]]],
]];
$text = $media->toText($body);
mediaCheck($text === "See below.\n\n<@{$b}>", 'Unknown media should show its id on its own line: ' . json_encode($text));
mediaCheck($media->needsLookup($text), 'An unknown id with uncached attachments needs a lookup.');
$media->resolve($text);
mediaCheck($client->lookups === ['1', '2'], 'Lookups should stop once every id is known.');
mediaCheck($media->toText($body) === "See below.\n\n<@shot.png>", 'Known ids should show the attachment name.');
mediaCheck(!$media->needsLookup("<@notes.txt>"), 'Names need no lookup.');

$client->lookups = [];
$document = $media->fromMarkdown("Files:\n<@1>\n<@shot.png>\nand <@3> inline\n\n<@my_file.txt>\n<@{$c}>");
mediaCheck($client->lookups === ['3'], 'Saving should look up only uncached attachments.');
$types = array_map(static fn(array $block): string => $block['type'] . ':' . ($block['content'][0]['attrs']['id'] ?? $block['content'][0]['text'] ?? ''), $document['content']);
mediaCheck($types === [
  'paragraph:Files:', "mediaGroup:{$a}", "mediaSingle:{$b}", 'paragraph:and <@3> inline', "mediaGroup:{$c}", "mediaGroup:{$c}",
], 'Token lines should become media blocks: ' . json_encode($types));
mediaRejects($media, '<@9>', 'row 9');
mediaRejects($media, '<@missing.txt>', 'missing.txt');
$twins = $issue;
$twins['fields']['attachment'][] = ['id' => '4', 'filename' => 'notes.txt'];
mediaRejects(new TicketMedia($twins, new MediaData($client)), '<@notes.txt>', 'row number');
echo "Ticket media checks passed.\n";
