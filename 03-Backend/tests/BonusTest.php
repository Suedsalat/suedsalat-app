<?php
declare(strict_types=1);

/**
 * Outtakes (App 2.0, intern "bonus"): nur Thorsten laedt hoch, nur angemeldete Hoerer sehen die Liste.
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/BonusTest.php
 */

require __DIR__ . '/TestHelpers.php';

const ADMIN_PASSWORD = 'geheim-test-123';

resetTables($pdo, ['listener_login_codes', 'listeners', 'devices', 'refresh_tokens', 'rate_limits', 'bonus_content']);
$pdo->exec("INSERT INTO admins (id, name, email, password_hash, role) VALUES (2, 'Jenny', 'jenny@test.local', 'x', 'member')
            ON DUPLICATE KEY UPDATE role = 'member'");
$pdo->prepare('UPDATE admins SET password_hash = :h, totp_enabled = 0 WHERE id IN (1, 2)')
    ->execute([':h' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
$owner = adminSession(1);
$jenny = adminSession(2);

// Kleinste gueltige MP3: ID3-Kopf und ein paar MPEG-Frames.
$mp3 = tempnam(sys_get_temp_dir(), 'bonus') . '.mp3';
file_put_contents($mp3, "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 20));
$txt = tempnam(sys_get_temp_dir(), 'bonus') . '.mp3';
file_put_contents($txt, 'das ist kein Ton');

echo "Admin-Bereich\n";
[$s, , $loc] = page($jenny, '/admin/bonus.php');
check($s === 302 && str_contains($loc, 'dashboard.php'), 'Jenny kommt nicht auf die Seite');
[, $html] = page($jenny, '/admin/dashboard.php');
check(!str_contains($html, 'bonus.php'), 'Jenny sieht keinen Menuepunkt');
[$s, $html] = page($owner, '/admin/bonus.php');
check($s === 200 && str_contains($html, 'Noch keine Outtakes') && clean($html), 'Thorsten sieht die leere Seite');
check(str_contains($html, 'href="' . BASE_PATH . '/admin/bonus.php"'), 'Thorsten hat den Menuepunkt');

[, $html] = page($owner, '/admin/bonus.php', ['action' => 'create', 'title' => 'Falsch', 'audio' => new CURLFile($txt, 'audio/mpeg')]);
check(str_contains($html, 'Nur MP3- oder M4A-Dateien') && (int) $pdo->query('SELECT COUNT(*) FROM bonus_content')->fetchColumn() === 0, 'keine Audiodatei: abgelehnt');
page($jenny, '/admin/bonus.php', ['action' => 'create', 'title' => 'Von Jenny', 'audio' => new CURLFile($mp3, 'audio/mpeg')]);
check((int) $pdo->query('SELECT COUNT(*) FROM bonus_content')->fetchColumn() === 0, 'Jenny kann auch per Formular nichts anlegen');
[$s, , $loc] = page($owner, '/admin/bonus.php', ['action' => 'create', 'title' => 'Outtakes Folge 36', 'description' => 'Was nicht in die Folge kam.', 'audio' => new CURLFile($mp3, 'audio/mpeg')]);
$b = $pdo->query('SELECT * FROM bonus_content')->fetch();
check($s === 302 && $b !== false && $b['title'] === 'Outtakes Folge 36', 'Thorsten legt einen Beitrag an');
$datei = UPLOAD_DIR . '/bonus/' . basename((string) $b['audio_url']);
check(is_file($datei) && str_starts_with((string) $b['audio_url'], UPLOAD_URL_BASE . '/bonus/'), 'Datei liegt unter uploads/bonus');

echo "App-Schnittstelle\n";
$gast = deviceToken('b-gast');
[$s, $r] = api('GET', '/api/bonus.php', $gast);
check($s === 403 && str_contains($r['error'] ?? '', 'Hörerkonto'), 'Gast bekommt keine Liste');
$hoerer = deviceToken('b-hoerer');
registerListener($hoerer, 'bonus@example.org', 'Bonnie');
[$s, $r] = api('GET', '/api/bonus.php', $hoerer);
check($s === 200 && count($r) === 1 && $r[0]['title'] === 'Outtakes Folge 36' && $r[0]['audio_url'] === $b['audio_url'], 'angemeldeter Hoerer sieht den Beitrag');
check(array_keys($r[0]) === ['id', 'title', 'description', 'audio_url', 'published_at'], 'nur die noetigen Felder');
$pdo->exec("UPDATE listeners SET blocked_at = NOW() WHERE nickname = 'Bonnie'");
[$s] = api('GET', '/api/bonus.php', $hoerer);
check($s === 200, 'gesperrte Hoerer duerfen weiter hoeren');

echo "Bearbeiten und Loeschen\n";
page($owner, '/admin/bonus.php', ['action' => 'update', 'id' => (string) $b['id'], 'title' => 'Outtakes 36 (neu)', 'description' => '']);
$neu = $pdo->query('SELECT * FROM bonus_content')->fetch();
check($neu['title'] === 'Outtakes 36 (neu)' && $neu['description'] === null && $neu['audio_url'] === $b['audio_url'], 'Titel geaendert, Datei bleibt');
page($owner, '/admin/bonus.php', ['action' => 'update', 'id' => (string) $b['id'], 'title' => 'Outtakes 36 (neu)', 'audio' => new CURLFile($mp3, 'audio/mpeg')]);
$neu = $pdo->query('SELECT * FROM bonus_content')->fetch();
clearstatcache();
$neueDatei = UPLOAD_DIR . '/bonus/' . basename((string) $neu['audio_url']);
check($neu['audio_url'] !== $b['audio_url'] && is_file($neueDatei) && !is_file($datei), 'neue Datei ersetzt die alte, alte ist weg');
[, , $loc] = page($owner, '/admin/bonus.php', ['delete_id' => (string) $b['id'], 'confirm_password' => 'falsch']);
check(str_contains($loc, 'delete_error') && is_file($neueDatei), 'falsches Passwort: nichts geloescht');
page($owner, '/admin/bonus.php', ['delete_id' => (string) $b['id'], 'confirm_password' => ADMIN_PASSWORD]);
clearstatcache(); // is_file() merkt sich sonst den Stand von vorhin
check((int) $pdo->query('SELECT COUNT(*) FROM bonus_content')->fetchColumn() === 0 && !is_file($neueDatei), 'geloescht samt Datei');

unlink($mp3);
unlink($txt);
finish();
