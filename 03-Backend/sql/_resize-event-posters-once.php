<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$secret = $_GET['secret'] ?? '';
if (!hash_equals('suedsalat-resize-2026', $secret)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$eventsDir = UPLOAD_DIR . '/events';
if (!is_dir($eventsDir)) {
    echo "Kein events-Upload-Verzeichnis gefunden.\n";
    exit;
}

$maxDimension = 1600;

function resize_poster_image(string $sourcePath, string $targetPath, string $mime, int $maxDimension): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($sourcePath),
        'image/png' => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if ($image === false) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, $maxDimension / max($width, $height));

    if ($scale < 1) {
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($image, $targetPath, 82),
        'image/png' => imagepng($image, $targetPath, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($image, $targetPath, 82) : false,
        default => false,
    };
    imagedestroy($image);

    return $saved;
}

$mimeByExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

foreach (scandir($eventsDir) as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }
    $path = $eventsDir . '/' . $file;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $mimeByExt[$ext] ?? null;
    if ($mime === null) {
        continue;
    }

    $before = filesize($path);
    $ok = resize_poster_image($path, $path, $mime, $maxDimension);
    $after = filesize($path);

    echo $ok
        ? "$file: $before -> $after Bytes\n"
        : "$file: uebersprungen (konnte nicht gelesen werden)\n";
}

echo "Fertig.\n";
