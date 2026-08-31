<?php
/**
 * auth.php — Guard de autenticación.
 * Incluir al inicio de las páginas protegidas: si no hay sesión activa,
 * redirige al formulario de login.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
