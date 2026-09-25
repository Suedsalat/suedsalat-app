<?php
declare(strict_types=1);

// Gemeinsame Sidebar-Navigation fuer alle admin/*.php-Seiten (ausser den
// Auth-Seiten login/2fa/logout/setup-account/forgot-password/reset-password,
// die keine Navigation brauchen) - ersetzt die bis 2026-09-16 in jeder Datei
// einzeln kopierte <header>+<nav>. Erwartet $isOwner (bool) sowie $adminId
// und $pdo im Scope der einbindenden Datei (ueberall bereits vorhanden).
// Einbindung: require direkt nach <body>, Gegenstueck ist sidebar-close.php
// kurz vor den <script>-Tags.
if (!isset($isOwner)) {
    throw new \RuntimeException('sidebar-open.php benoetigt $isOwner im Scope der einbindenden Seite.');
}

$currentAdminPage = basename($_SERVER['SCRIPT_NAME']);

// Name des gerade angemeldeten Admins - eigene kleine Abfrage statt den
// jeweils schon vorhandenen $admin/$currentAdminRole-Variablen der
// einzelnen Seiten zu vertrauen (die heissen nicht ueberall gleich).
$sidebarAdminName = null;
if (isset($adminId, $pdo)) {
    $sidebarAdminNameStmt = $pdo->prepare('SELECT name FROM admins WHERE id = :id');
    $sidebarAdminNameStmt->execute([':id' => $adminId]);
    $sidebarAdminName = $sidebarAdminNameStmt->fetchColumn() ?: null;
}

// Offene Meldungen (App 2.0) als Zahl am Menuepunkt. try/catch, damit die Seitenleiste auch
// ohne 2.0-Tabellen funktioniert (z. B. wenn diese Datei vor der Migration live geht).
$sidebarOpenReports = 0;
if (isset($pdo)) {
    try {
        $sidebarOpenReports = (int) $pdo->query("SELECT COUNT(DISTINCT content_type, content_id) FROM content_reports WHERE status = 'open'")->fetchColumn();
    } catch (\PDOException $e) {
        $sidebarOpenReports = 0;
    }
}

function admin_nav_active(string $page, string $current): string
{
    return $page === $current ? 'is-active' : '';
}
?>
<div class="admin-shell">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle">
        <span class="bars"><span></span><span></span><span></span></span>
        Menü
    </button>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
    <nav class="admin-sidebar" id="admin-sidebar">
        <div class="sidebar-brand">
            <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/../assets/img/logo.png') ?>" alt="Südsalat">
            <span>
                APP-Administrationsbereich
                <?php if ($sidebarAdminName !== null): ?>
                    <small>Angemeldet als <?= htmlspecialchars($sidebarAdminName, ENT_QUOTES) ?></small>
                <?php endif; ?>
            </span>
        </div>

        <div class="sidebar-group-label">Inhalte</div>
        <a class="<?= admin_nav_active('dashboard.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/dashboard.php">Dashboard</a>
        <a class="<?= admin_nav_active('feedback.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/feedback.php">Aktivitäten</a>
        <a class="<?= admin_nav_active('events.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/events.php">Veranstaltungen</a>
        <a class="<?= admin_nav_active('gallery.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/gallery.php">Galerie</a>
        <a class="<?= admin_nav_active('movie-tips.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/movie-tips.php">Filmtipps</a>
        <a class="<?= admin_nav_active('location-tips.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/location-tips.php">Locations</a>
        <a class="<?= admin_nav_active('tip-reviews.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/tip-reviews.php">Rezensionen</a>
        <?php if ($isOwner): ?>
        <a class="<?= admin_nav_active('bonus.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/bonus.php">Bonus und Outtakes</a>
        <?php endif; ?>

        <div class="sidebar-group-label">Hörer</div>
        <a class="<?= admin_nav_active('listeners.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/listeners.php">Hörerkonten</a>
        <a class="<?= admin_nav_active('reports.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/reports.php">Meldungen<?= $sidebarOpenReports > 0 ? ' (' . $sidebarOpenReports . ')' : '' ?></a>

        <div class="sidebar-group-label">Auswertung</div>
        <a class="<?= admin_nav_active('statistics.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/statistics.php">Statistiken</a>
        <?php if ($isOwner): ?>
        <a class="<?= admin_nav_active('newsletter.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/newsletter.php">Newsletter</a>
        <a class="<?= admin_nav_active('newsletter-lists.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/newsletter-lists.php">Empfängerlisten</a>
        <?php endif; ?>

        <div class="sidebar-group-label">Konto</div>
        <?php if ($isOwner): ?>
        <a class="<?= admin_nav_active('users.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/users.php">Benutzerverwaltung</a>
        <?php endif; ?>
        <a class="<?= admin_nav_active('change-password.php', $currentAdminPage) ?>" href="<?= BASE_PATH ?>/admin/change-password.php">Passwort ändern</a>
        <a href="<?= BASE_PATH ?>/admin/logout.php">Abmelden (<span id="logout-countdown" data-timeout-seconds="<?= ADMIN_IDLE_TIMEOUT_MINUTES * 60 ?>"></span>)</a>
    </nav>
    <div class="admin-main">
