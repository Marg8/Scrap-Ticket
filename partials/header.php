<?php
/**
 * Scrap Ticket System – Reusable site header (Littelfuse Matamoros layout).
 *
 * Before including this file, a view may define:
 *   $active_page   string  Key of the active menu item (see $menu below).
 *   $page_subtitle string  Optional page-specific subtitle shown under the menu.
 */

$active_page   = $active_page   ?? '';
$page_subtitle = $page_subtitle ?? '';
$db_offline    = $db_offline    ?? false;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_user = $_SESSION['user']['displayName'] ?? ($_SESSION['user']['username'] ?? null);
if (function_exists('schema_warnings_flush_to_console')) {
    schema_warnings_flush_to_console();
}

$menu = [
    'index'  => ['label' => 'Tickets',      'url' => 'index.php'],
    'create' => ['label' => 'Nuevo Ticket', 'url' => 'create_ticket.php'],
    'setup'  => ['label' => 'Setup',        'url' => 'setup.php'],
];

// Muestra "Admin" solo si el usuario actual es administrador (o modo arranque).
$is_real_admin  = false;
$effective_role = null;
if (!$db_offline && $current_user !== null && function_exists('get_db_or_null')) {
    require_once __DIR__ . '/../admin_lib.php';
    $header_pdo = get_db_or_null();
    if ($header_pdo !== null) {
        $is_real_admin  = admin_real_role($header_pdo) === ROLE_ADMIN;
        $effective_role = admin_effective_role($header_pdo);
        if (admin_can_see_panel($header_pdo)) {
            $menu['admin'] = ['label' => 'Admin', 'url' => 'admin.php'];
        }
    }
}

// ⛔ ENTORNO MYSQL TEMPORAL: autorización del switch por sesión (sin BD),
// para poder cambiar de entorno aunque la conexión esté caída.
// Eliminar junto con .env.temp al indicar "borrar el entorno mysql temporal".
$temp_toggle_allowed = false;
if (defined('DEV_TOGGLE_EMAIL')) {
    $sess_mail = strtolower(trim($_SESSION['user']['mail']     ?? ''));
    $sess_user = strtolower(trim($_SESSION['user']['username'] ?? ''));
    $allowed   = strtolower(DEV_TOGGLE_EMAIL);
    $temp_toggle_allowed = ($sess_mail === $allowed) || ($sess_user !== '' && $sess_user === explode('@', $allowed)[0]);
}
?>
<header class="lf-header">
    <div class="lf-banner">
        <span class="lf-welcome">Bienvenido [<b><?= htmlspecialchars($current_user ?? 'Invitado') ?></b>]!</span>
        <span class="lf-accent">|</span>
        <a href="index.php" class="lf-banner-link">Inicio</a>
        <span class="lf-accent">|</span>
        <?php if ($current_user !== null): ?>
            <a href="login.php?logout=1" class="lf-banner-link">Cerrar sesión</a>
            <span class="lf-accent">|</span>
        <?php endif; ?>
    </div>

    <div class="lf-logobar">
        <a href="index.php" class="lf-logo-link">
            <img src="assets/images/Littelfuse.png" alt="Littelfuse" class="lf-logo">
        </a>
        <span class="lf-portal-title">Littelfuse Matamoros&nbsp;&ndash;&nbsp;Scrap Ticket System</span>
    </div>

    <nav class="lf-menu">
        <ul>
            <?php foreach ($menu as $key => $item): ?>
                <li class="<?= $key === $active_page ? 'current' : '' ?>">
                    <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>">
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>

<?php /* ⛔ ENTORNO MYSQL TEMPORAL — switch; eliminar al "borrar el entorno mysql temporal". */ ?>
<?php if ($temp_toggle_allowed): ?>
<?php $temp_active = defined('TEMP_DB_ACTIVE') && TEMP_DB_ACTIVE; ?>
<div class="temp-db-banner">
    🧪 Base de datos actual:
    <strong><?= $temp_active ? 'MySQL TEMPORAL (nube)' : 'Config normal (XAMPP / respaldo)' ?></strong>
    <a href="toggle_temp_db.php?state=<?= $temp_active ? '0' : '1' ?>" class="temp-db-switch">
        <?= $temp_active ? '→ Cambiar a XAMPP' : '→ Cambiar a Temporal (nube)' ?>
    </a>
</div>
<?php elseif (defined('TEMP_DB_ACTIVE') && TEMP_DB_ACTIVE): ?>
<div class="temp-db-banner">
    🧪 Entorno <strong>MySQL TEMPORAL</strong> activo (base en la nube, sin instalación local).
    Se eliminará en cuanto indiques <em>“borrar el entorno mysql temporal”</em>.
</div>
<?php endif; ?>

<?php if ($is_real_admin): ?>
<?php
    $role_labels = [
        ROLE_ADMIN      => 'Administrador',
        ROLE_CAPTURISTA => 'Capturista',
        ROLE_APROBADOR  => 'Aprobador',
        ROLE_OBSERVADOR => 'Observador',
    ];
?>
<div class="lf-viewas">
    <span class="lf-viewas-label">👁 Ver como:</span>
    <?php foreach (admin_viewable_roles() as $rk): ?>
        <a href="switch_view.php?role=<?= urlencode($rk) ?>"
           class="lf-viewas-btn <?= $effective_role === $rk ? 'active' : '' ?>">
            <?= htmlspecialchars($role_labels[$rk]) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($is_real_admin && $effective_role !== ROLE_ADMIN): ?>
<div class="lf-preview-banner">
    Estás viendo el sitio como <strong><?= htmlspecialchars($role_labels[$effective_role] ?? $effective_role) ?></strong> (modo previsualización).
    <a href="switch_view.php?role=admin">Volver a Administrador</a>
</div>
<?php endif; ?>

<?php if ($db_offline): ?>
<div class="db-offline-banner">⚠ Sin conexión a la base de datos. No se procesará ningún requerimiento hasta que se restablezca la conexión.</div>
<?php endif; ?>

<?php if ($page_subtitle !== ''): ?>
<div class="lf-page-subtitle"><?= htmlspecialchars($page_subtitle, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
