<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Fail before any test migration if a cached config could redirect to real data.
if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'task_manager_dashboard_test'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '33317'
    || getenv('DB_URL') !== '') {
    throw new RuntimeException('MySQL tests require the dedicated local task_manager_dashboard_test database and uncached config.');
}
