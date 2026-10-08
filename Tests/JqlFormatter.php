<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\JqlFormatter;

$jql = 'project = "AP" AND (summary ~ "A AND B" OR description ~ "C") AND status in ("Open", "Closed") ORDER BY updated DESC';
$formatted = "project = \"AP\"\nAND (summary ~ \"A AND B\" OR description ~ \"C\")\nAND status in (\"Open\", \"Closed\")\nORDER BY updated DESC";
if (JqlFormatter::format($jql) !== $formatted) {
  throw new RuntimeException('JQL should wrap only top-level logical units.');
}
if (JqlFormatter::format('summary ~ "A \\" OR B" OR status = Open') !== "summary ~ \"A \\\" OR B\"\nOR status = Open") {
  throw new RuntimeException('Quoted operators must remain inside their values.');
}
echo "JQL formatter OK\n";
