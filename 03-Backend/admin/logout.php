<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;

Auth::logout();
header('Location: ' . BASE_PATH . '/admin/login.php');
exit;
