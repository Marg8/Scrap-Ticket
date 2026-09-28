<?php
/**
 * toggle_temp_db.php — ⛔ ENTORNO MYSQL TEMPORAL
 * Alterna USE_TEMP_DB en .env.temp (nube ↔ XAMPP local) sin editar archivos
 * a mano. No depende de la conexión a BD para poder repararla si está caída.
 * Se elimina junto con .env.temp al indicar "borrar el entorno mysql temporal".
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// Autorización simple por correo/usuario de sesión (sin tocar la BD).
$mail     = strtolower(trim($_SESSION['user']['mail']     ?? ''));
$username = strtolower(trim($_SESSION['user']['username'] ?? ''));
$allowed  = strtolower(DEV_TOGGLE_EMAIL);
$is_authorized = ($mail === $allowed) || ($username !== '' && $username === explode('@', $allowed)[0]);

if (!$is_authorized) {
    header('Location: index.php?error=forbidden');
    exit;
}

$path = __DIR__ . '/.env.temp';
if (is_readable($path) && is_writable($path)) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $wantState = isset($_GET['state']) && $_GET['state'] === '1' ? '1' : '0';
    $found = false;
    foreach ($lines as &$line) {
        if (preg_match('/^\s*USE_TEMP_DB\s*=/', $line)) {
            $line  = 'USE_TEMP_DB=' . $wantState;
            $found = true;
        }
    }
    unset($line);
    if (!$found) {
        $lines[] = 'USE_TEMP_DB=' . $wantState;
    }
    file_put_contents($path, implode("\n", $lines) . "\n");
}

// Regreso seguro: solo se acepta el referer si es del mismo host.
$back = 'index.php';
$ref  = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '') {
    $refHost = parse_url($ref, PHP_URL_HOST);
    if ($refHost === null || $refHost === '' || $refHost === ($_SERVER['HTTP_HOST'] ?? '')) {
        $back = $ref;
    }
}
header('Location: ' . $back);
exit;
