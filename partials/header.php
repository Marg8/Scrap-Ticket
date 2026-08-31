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

$menu = [
    'index'  => ['label' => 'Tickets',      'url' => 'index.php'],
    'create' => ['label' => 'Nuevo Ticket', 'url' => 'create_ticket.php'],
    'setup'  => ['label' => 'Setup',        'url' => 'setup.php'],
];
?>
<header class="lf-header">
    <div class="lf-banner">
        <span class="lf-welcome">Bienvenido [<b>Usuario</b>] SysAdministrator!</span>
        <span class="lf-accent">|</span>
        <a href="index.php" class="lf-banner-link">Inicio</a>
        <span class="lf-accent">|</span>
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

<?php if ($page_subtitle !== ''): ?>
<div class="lf-page-subtitle"><?= htmlspecialchars($page_subtitle, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
