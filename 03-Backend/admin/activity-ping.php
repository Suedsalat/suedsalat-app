<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;

// Verlaengert die Session bei Aktivitaet (z.B. Tippen in einem Formular), ohne
// dass dafuer ein kompletter Seiten-Neuaufbau noetig ist - Auth::requireLogin()
// aktualisiert dabei $_SESSION['last_activity'] genau wie ein normaler
// Seitenaufruf. Wird per JS (session-countdown.js) im Hintergrund aufgerufen.
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'ok']);
