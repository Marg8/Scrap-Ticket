<?php
/**
 * switch_view.php — Permite a un administrador previsualizar el sitio como otro
 * rol ("Ver como"). Solo el usuario con rol REAL admin puede usarlo.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_lib.php';

$pdo = get_db_or_null();
if ($pdo !== null && admin_real_role($pdo) === ROLE_ADMIN) {
    admin_set_view_as($_GET['role'] ?? null);
}

// Regreso seguro: solo se acepta el referer si es del mismo host (evita open redirect).
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
