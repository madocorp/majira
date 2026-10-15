<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\Jira\Adf;

/** Report a failed ADF conversion expectation. */
function adfCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$nodes = Adf::fromMarkdown('A <https://a.io/x?y=1> [the **docs**](https://b.io) https://c.io')['content'][0]['content'];
$link = fn(string $href): array => ['type' => 'link', 'attrs' => ['href' => $href]];
adfCheck($nodes[1] === ['type' => 'text', 'text' => 'https://a.io/x?y=1', 'marks' => [$link('https://a.io/x?y=1')]], 'An <url> must become a link.');
adfCheck($nodes[3] === ['type' => 'text', 'text' => 'the ', 'marks' => [$link('https://b.io')]]
  && $nodes[4] === ['type' => 'text', 'text' => 'docs', 'marks' => [['type' => 'strong'], $link('https://b.io')]], 'A [label](url) must link every label part and keep its marks.');
adfCheck($nodes[5] === ['type' => 'text', 'text' => ' https://c.io'], 'A bare URL must stay plain text.');
adfCheck(Adf::fromMarkdown('<ftp://x.io> [a](javascript:x)')['content'][0]['content'] === [['type' => 'text', 'text' => '<ftp://x.io> [a](javascript:x)']], 'Only http(s) and mailto links are converted.');

$markdown = 'See <https://a.io/x> and [the **docs**](https://b.io) or https://c.io plus `code`.';
adfCheck(Adf::toText(Adf::fromMarkdown($markdown)) === $markdown, 'Links must survive a Markdown round trip.');
$card = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'inlineCard', 'attrs' => ['url' => 'https://j.io/browse/AP-1']]]]]];
adfCheck(Adf::toText($card) === '<https://j.io/browse/AP-1>', 'Jira smart links must read as <url>.');
adfCheck(Adf::fromMarkdown('See <#AP-12>')['content'][0]['content'][1] === ['type' => 'text', 'text' => '<#AP-12>'], 'A ticket link stays text until the Jira site is known.');
Adf::setSite('https://j.io/');
adfCheck(Adf::fromMarkdown('See <#AP-12>')['content'][0]['content'][1] === ['type' => 'inlineCard', 'attrs' => ['url' => 'https://j.io/browse/AP-12']], 'A <#KEY> must become a Jira ticket card.');
adfCheck(Adf::toText($card) === '<#AP-1>' && Adf::toText(Adf::fromMarkdown('A <#AP-12> b')) === 'A <#AP-12> b', 'Ticket cards of the same site must read as <#KEY>.');
adfCheck(Adf::fromMarkdown('<#ap-1> <#AP>')['content'][0]['content'] === [['type' => 'text', 'text' => '<#ap-1> <#AP>']], 'Only upper case ticket keys are converted.');
echo "ADF checks passed.\n";
