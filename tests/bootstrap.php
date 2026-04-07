<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load test environment
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}
