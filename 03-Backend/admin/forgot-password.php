<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Mailer;

Auth::startSession();

// Verschickt den Freischaltungscode fuer genau diesen Admin und merkt sich
// den laufenden Reset in der Session - gemeinsam genutzt vom "frischen"
// Anfordern (per E-Mail-Adresse, von admin/login.php aus) und vom "Neuen Code
// anfordern" auf admin/reset-password.php (kennt die E-Mail-Adresse schon aus
// der Session, muss also nicht nochmal gefragt werden).
function send_reset_code(\PDO $pdo, array $admin, string $email): void
{
    $code = (string) random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $expiresAt = (new DateTime())->modify('+' . PASSWORD_RESET_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');

    $insert = $pdo->prepare(
        'INSERT INTO password_resets (admin_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, :expires_at)'
    );
    $insert->execute([
        ':admin_id' => $admin['id'],
        ':token_hash' => $codeHash,
        ':expires_at' => $expiresAt,
    ]);

    try {
        Mailer::send(
            $email,
            $admin['name'],
            'Dein Freischaltungscode – Südsalat',
            "<p>Hallo {$admin['name']},</p>
             <p>Dein Freischaltungscode zum Zurücksetzen deines Passworts lautet:</p>
             <p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">$code</p>
             <p>Gib ihn auf der Seite \"Neues Passwort vergeben\" ein. Der Code ist " . PASSWORD_RESET_TTL_MINUTES . " Minuten gültig.</p>
             <p>Falls du das nicht angefordert hast, ignoriere diese E-Mail.</p>"
        );
    } catch (\Throwable $e) {
        error_log('Mailversand fehlgeschlagen: ' . $e->getMessage());
    }
}

$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['resend'])) {
    // "Neuen Code anfordern" von reset-password.php aus - E-Mail-Adresse ist
    // schon aus der laufenden Session bekannt, wird nicht erneut abgefragt.
    $pendingAdminId = $_SESSION['pending_reset_admin_id'] ?? null;
    if ($pendingAdminId !== null) {
        $stmt = $pdo->prepare('SELECT id, name, email FROM admins WHERE id = :id');
        $stmt->execute([':id' => $pendingAdminId]);
        $admin = $stmt->fetch();
        if ($admin) {
            unset($_SESSION['pending_reset_verified_id']);
            send_reset_code($pdo, $admin, $admin['email']);
        }
    }
    header('Location: ' . BASE_PATH . '/admin/reset-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    // Normalfall: von admin/login.php aus, E-Mail-Feld war dort schon
    // ausgefuellt - kein eigener "E-Mail eingeben"-Schritt mehr noetig.
    $email = normalize_email((string) $_POST['email']);
    $stmt = $pdo->prepare('SELECT id, name FROM admins WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch();

    unset($_SESSION['pending_reset_verified_id']);
    $_SESSION['pending_reset_masked_email'] = mask_email_for_display($email);
    $_SESSION['pending_reset_admin_id'] = $admin ? (int) $admin['id'] : null;

    if ($admin) {
        send_reset_code($pdo, $admin, $email);
    }

    header('Location: ' . BASE_PATH . '/admin/reset-password.php');
    exit;
}

// Direkter Aufruf ohne POST (z.B. Lesezeichen) - es gibt hier nichts
// anzuzeigen, der Ablauf startet immer ueber den Login.
header('Location: ' . BASE_PATH . '/admin/login.php');
exit;
