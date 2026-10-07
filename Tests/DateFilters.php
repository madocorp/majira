<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\{FilterState, JqlBuilder, Settings};

function expectDateFilter(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$testHome = sys_get_temp_dir() . '/majira-date-test-' . bin2hex(random_bytes(8));
mkdir($testHome, 0700);
putenv('HOME=' . $testHome);
Settings::save([]);
$builder = new JqlBuilder();
$legacy = FilterState::normalize(['created' => '2024-02-29']);
expectDateFilter($legacy['created'] === '2024-02-29' && $legacy['createdTo'] === '', 'Existing since dates must migrate.');
expectDateFilter(!FilterState::validDate('2023-02-29'), 'Invalid calendar dates must be rejected.');
expectDateFilter($builder->generatedFor(FilterState::defaults()) === '', 'Both dates must be empty by default.');
expectDateFilter($builder->generatedFor(['createdMode' => 'any', 'created' => '2024-02-29']) === '', 'Previous Any mode must migrate to empty dates.');
expectDateFilter($builder->generatedFor($legacy) === 'created >= "2024-02-29"', 'Since must include its start date.');
expectDateFilter($builder->generatedFor(['updatedMode' => 'until', 'updated' => '2024-02-29']) === 'updated < "2024-03-01"', 'Until must include the whole chosen day.');
expectDateFilter(FilterState::normalize(['createdMode' => 'between', 'created' => '2024-02-28', 'createdTo' => '2024-02-29'])['createdTo'] === '2024-02-29', 'Previous Between mode must retain both dates.');
expectDateFilter($builder->generatedFor(['created' => '2024-02-28', 'createdTo' => '2024-02-29']) === 'created >= "2024-02-28" AND created < "2024-03-01"', 'Two dates must include both endpoints.');
foreach (glob($testHome . '/.majira/*') ?: [] as $file) {
  unlink($file);
}
rmdir($testHome . '/.majira');
rmdir($testHome);
echo "Date filters OK\n";
