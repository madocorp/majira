<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MAJIRA');
require_once APP_DIR . '/SPTK/App.php';

use MAJIRA\App\FieldConfiguration;

/** Fail a field configuration check with a useful message. */
function fieldCheck(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$settings = [
  'fields' => ['customfield_10000', 'status', 'key', 'customfield_10000'],
  'fieldOptions' => [
    ['id' => 'customfield_10000', 'name' => 'Story points'],
    ['id' => 'issuetype', 'name' => 'Issue Type'],
    ['id' => 'invalid field', 'name' => 'Bad'],
  ],
];
fieldCheck(FieldConfiguration::selected($settings) === ['customfield_10000', 'status', 'key'], 'Saved field order should be deduplicated.');
$options = FieldConfiguration::options($settings);
fieldCheck($options['customfield_10000'] === 'Story points' && $options['type'] === 'Type', 'Jira custom fields should join built-in choices without duplicating Type.');
fieldCheck(!isset($options['invalid field']), 'Invalid Jira field IDs should be excluded.');
fieldCheck(FieldConfiguration::jiraId('type') === 'issuetype' && FieldConfiguration::jiraId('key') === null, 'Display fields should map to Jira request fields.');
fieldCheck(FieldConfiguration::requestedFields(['summary', 'status'], $settings) === ['summary', 'status', 'customfield_10000'], 'Jira requests should include selected custom fields without duplicates.');
$issue = ['key' => 'AP-7', 'fields' => [
  'summary' => 'A long\nsummary',
  'status' => ['name' => 'In Progress'],
  'customfield_10000' => 5,
  'labels' => ['one', 'two'],
  'assignee' => ['displayName' => 'Ada'],
  'parent' => ['key' => 'AP-1', 'fields' => ['summary' => 'Parent']],
]];
fieldCheck(FieldConfiguration::value($issue, 'key') === 'AP-7', 'Key should read from the issue root.');
fieldCheck(FieldConfiguration::value($issue, 'status') === 'In Progress', 'Jira option names should be displayed.');
fieldCheck(FieldConfiguration::value($issue, 'customfield_10000') === '5', 'Custom numeric fields should be displayed.');
fieldCheck(FieldConfiguration::value($issue, 'labels') === 'one, two', 'String arrays should be joined for display.');
fieldCheck(FieldConfiguration::value($issue, 'assignee') === 'Ada', 'User display names should be used.');
fieldCheck(FieldConfiguration::value($issue, 'parent.key') === 'AP-1', 'Parent fields should use the parent issue.');
echo "Field configuration checks passed.\n";
