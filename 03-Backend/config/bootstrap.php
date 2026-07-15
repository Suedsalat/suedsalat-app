<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // Fehler nie an den Client ausgeben, nur ins Log.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Berlin');
