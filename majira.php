#!/usr/bin/env php
<?php

define('APP_DIR', __DIR__);
define('APP_NAMESPACE', 'MAJIRA');

require_once APP_DIR . '/SPTK/App.php';

// The argument is a ticket key or a Jira ticket URL.
if (\MAJIRA\App\Instance::forward(trim($argv[1] ?? ''))) {
  exit(0);
}
new \SPTK\App;
