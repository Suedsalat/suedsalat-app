<?php
declare(strict_types=1);

// Temporaeres Diagnose-Snippet: wird von login.php und forgot-password.php
// eingebunden, um die rohen POST-Daten in eine Log-Datei zu schreiben.
// NACH GEBRAUCH WIEDER ENTFERNEN.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = $_POST['email'] ?? '(kein email-Feld)';
    $entry = sprintf(
        "[%s] Seite=%s RohEmail=%s Hex=%s Laenge=%d\n",
        date('Y-m-d H:i:s'),
        $_SERVER['SCRIPT_NAME'],
        $raw,
        bin2hex($raw),
        strlen($raw)
    );
    file_put_contents(__DIR__ . '/debug-capture.log', $entry, FILE_APPEND | LOCK_EX);
}
