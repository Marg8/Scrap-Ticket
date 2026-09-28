<?php
/**
 * admin.php — Panel administrativo: Roles, Niveles DOA y Suplencias (Backups).
 * Solo accesible para el rol Administrador (o el primer usuario en modo arranque).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_lib.php';
require_once __DIR__ . '/ldap_lookup.php';

$pdo        = get_db_or_null();
$db_offline = $pdo === null;

function admin_flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function admin_redirect(string $tab = 'roles'): void {
    header('Location: admin.php?tab=' . urlencode($tab));
    exit;
}

$bootstrap = false;

if (!$db_offline) {
    admin_require_admin($pdo);
    $bootstrap = admin_is_bootstrap($pdo);

    // ── Procesamiento de acciones (PRG con mensajes flash) ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!admin_check_csrf()) {
            admin_flash('danger', 'Token de seguridad inválido. Intenta de nuevo.');
            admin_redirect();
        }

        $action = $_POST['action'] ?? '';
        $tab    = $_POST['tab'] ?? 'roles';

        try {
            switch ($action) {

                // ── Roles / Usuarios ──
                case 'save_user': {
                    $email   = strtolower(trim($_POST['email'] ?? ''));
                    $role_id = (int) ($_POST['role_id'] ?? 0);
                    $nombre  = trim($_POST['nombre_completo'] ?? '');

                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        admin_flash('danger', 'Correo inválido.');
                    } elseif (!str_ends_with($email, '@' . CORP_EMAIL_DOMAIN)) {
                        admin_flash('danger', 'El correo debe ser corporativo (@' . CORP_EMAIL_DOMAIN . ').');
                    } elseif ($role_id <= 0) {
                        admin_flash('danger', 'Selecciona un rol válido.');
                    } else {
                        $username = '';
                        if ($nombre === '') {
                            $info     = ldap_lookup_by_email($email);
                            $nombre   = $info['nombre_completo'];
                            $username = $info['username'];
                        }
                        admin_upsert_user($pdo, $email, $nombre, $role_id, $username);
                        admin_flash('success', 'Usuario guardado: ' . htmlspecialchars($nombre) . '.');
                    }
                    break;
                }

                case 'delete_user': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
                    $stmt->execute([$id]);
                    admin_flash('success', 'Usuario eliminado.');
                    break;
                }

                // ── Niveles DOA ──
                case 'save_level': {
                    $nivel  = (int) ($_POST['nivel'] ?? 0);
                    $nombre = trim($_POST['nombre'] ?? '');
                    $min    = filter_var($_POST['min_amount'] ?? '', FILTER_VALIDATE_FLOAT);
                    $maxRaw = trim($_POST['max_amount'] ?? '');
                    $max    = $maxRaw === '' ? null : filter_var($maxRaw, FILTER_VALIDATE_FLOAT);

                    if ($nivel < 1 || $nivel > 10) {
                        admin_flash('danger', 'El nivel debe estar entre 1 y 10.');
                    } elseif ($nombre === '') {
                        admin_flash('danger', 'El nombre del nivel es obligatorio.');
                    } elseif ($min === false || $min < 0) {
                        admin_flash('danger', 'El monto mínimo no es válido.');
                    } elseif ($max !== null && ($max === false || $max < $min)) {
                        admin_flash('danger', 'El monto máximo debe ser mayor o igual al mínimo (o vacío = sin límite).');
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO doa_niveles (nivel, nombre, min_amount, max_amount, activo)
                            VALUES (:nivel, :nombre, :min, :max, 1)
                            ON DUPLICATE KEY UPDATE
                                nombre = VALUES(nombre),
                                min_amount = VALUES(min_amount),
                                max_amount = VALUES(max_amount)
                        ');
                        $stmt->execute([':nivel' => $nivel, ':nombre' => $nombre, ':min' => $min, ':max' => $max]);
                        admin_flash('success', 'Nivel DOA ' . $nivel . ' guardado.');
                    }
                    break;
                }

                case 'delete_level': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('DELETE FROM doa_niveles WHERE id = ?');
                    $stmt->execute([$id]);
                    admin_flash('success', 'Nivel DOA eliminado.');
                    break;
                }

                // ── Asignación usuario ↔ nivel ──
                case 'assign_doa': {
                    $nivel_id   = (int) ($_POST['nivel_id'] ?? 0);
                    $usuario_id = (int) ($_POST['usuario_id'] ?? 0);
                    if ($nivel_id <= 0 || $usuario_id <= 0) {
                        admin_flash('danger', 'Selecciona un usuario y un nivel.');
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO doa_usuarios (nivel_id, usuario_id)
                            VALUES (:nivel, :usuario)
                            ON DUPLICATE KEY UPDATE nivel_id = VALUES(nivel_id)
                        ');
                        $stmt->execute([':nivel' => $nivel_id, ':usuario' => $usuario_id]);
                        admin_flash('success', 'Usuario asignado al nivel DOA.');
                    }
                    break;
                }

                case 'remove_doa': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('DELETE FROM doa_usuarios WHERE id = ?');
                    $stmt->execute([$id]);
                    admin_flash('success', 'Asignación removida.');
                    break;
                }

                // ── Suplencias ──
                case 'save_backup': {
                    $titular_id = (int) ($_POST['titular_id'] ?? 0);
                    $backup_id  = (int) ($_POST['backup_id'] ?? 0);
                    $ini        = trim($_POST['fecha_inicio'] ?? '');
                    $fin        = trim($_POST['fecha_fin'] ?? '');

                    if ($titular_id <= 0 || $backup_id <= 0) {
                        admin_flash('danger', 'Selecciona titular y backup.');
                    } elseif ($titular_id === $backup_id) {
                        admin_flash('danger', 'El titular y el backup no pueden ser el mismo usuario.');
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO usuario_backups (titular_id, backup_id, fecha_inicio, fecha_fin, activo)
                            VALUES (:t, :b, :ini, :fin, 1)
                        ');
                        $stmt->execute([
                            ':t'   => $titular_id,
                            ':b'   => $backup_id,
                            ':ini' => $ini !== '' ? $ini : null,
                            ':fin' => $fin !== '' ? $fin : null,
                        ]);
                        admin_flash('success', 'Suplencia registrada.');
                    }
                    break;
                }

                case 'toggle_backup': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE usuario_backups SET activo = 1 - activo WHERE id = ?');
                    $stmt->execute([$id]);
                    admin_flash('success', 'Estado de la suplencia actualizado.');
                    break;
                }

                case 'delete_backup': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('DELETE FROM usuario_backups WHERE id = ?');
                    $stmt->execute([$id]);
                    admin_flash('success', 'Suplencia eliminada.');
                    break;
                }

                default:
                    admin_flash('danger', 'Acción no reconocida.');
            }
        } catch (PDOException $e) {
            error_log('admin.php action error: ' . $e->getMessage());
            admin_flash('danger', 'No se pudo completar la operación. Revisa los datos e intenta de nuevo.');
        }

        admin_redirect($tab);
    }
}

// ── Datos para la vista ──
$roles       = $db_offline ? [] : admin_all_roles($pdo);
$users       = $db_offline ? [] : admin_all_users($pdo);
$levels      = $db_offline ? [] : admin_all_levels($pdo);
$assignments = $db_offline ? [] : admin_level_assignments($pdo);
$backups     = $db_offline ? [] : admin_all_backups($pdo);
$csrf        = admin_csrf_token();
$active_tab  = in_array($_GET['tab'] ?? '', ['roles', 'doa', 'backups'], true) ? $_GET['tab'] : 'roles';
$flashes     = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

// Agrupar asignaciones por nivel para render.
$assign_by_level = [];
foreach ($assignments as $a) {
    $assign_by_level[(int) $a['nivel_id']][] = $a;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); margin:20px 0 0; flex-wrap:wrap; }
        .tab-btn {
            border:1px solid var(--border); border-bottom:none; background:var(--light);
            padding:10px 18px; cursor:pointer; font-weight:600; font-size:14px; color:var(--muted);
            border-radius:var(--radius) var(--radius) 0 0;
        }
        .tab-btn.active { background:#fff; color:var(--primary-dark); border-color:var(--primary); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        .inline-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .muted-sm { font-size:12px; color:var(--muted); }
        .doa-users { list-style:none; margin:6px 0 0; padding:0; }
        .doa-users li { display:flex; align-items:center; gap:8px; padding:3px 0; }
        table td .form-control, table td select.form-control { min-width:120px; }
    </style>
</head>
<body>

<?php
$active_page   = 'admin';
$page_subtitle = 'Administración';
require __DIR__ . '/partials/header.php';
?>

<div class="container">
    <h1 class="page-title">Panel de Administración</h1>

    <?php if ($db_offline): ?>
        <div class="alert alert-danger">Sin conexión a la base de datos. No se puede administrar la configuración.</div>
    <?php else: ?>

        <?php if ($bootstrap): ?>
            <div class="alert alert-warning">
                ⚠ Aún no hay administradores registrados. Estás en <strong>modo arranque</strong>:
                agrega tu propio correo con el rol <strong>Administrador</strong> para asegurar el acceso.
            </div>
        <?php endif; ?>

        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['msg']) ?></div>
        <?php endforeach; ?>

        <div class="tabs">
            <button class="tab-btn" data-tab="roles">1 · Roles</button>
            <button class="tab-btn" data-tab="doa">2 · Niveles DOA</button>
            <button class="tab-btn" data-tab="backups">3 · Suplencias</button>
        </div>

        <!-- ══════════ TAB 1: ROLES ══════════ -->
        <div class="tab-panel" id="tab-roles">
            <div class="card" style="border-top-left-radius:0;">
                <div class="card-header"><h2>Agregar / actualizar usuario</h2></div>
                <div class="card-body">
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="save_user">
                        <input type="hidden" name="tab" value="roles">
                        <input type="email" name="email" class="form-control" required
                               placeholder="correo@<?= htmlspecialchars(CORP_EMAIL_DOMAIN) ?>" style="min-width:240px;">
                        <input type="text" name="nombre_completo" class="form-control"
                               placeholder="Nombre (auto por LDAP si se deja vacío)" style="min-width:220px;">
                        <select name="role_id" class="form-control" required>
                            <option value="">Rol…</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </form>
                    <p class="muted-sm" style="margin-top:8px;">
                        El nombre completo se obtiene automáticamente del directorio (LDAP) a partir del correo corporativo.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Matriz de roles (<?= count($users) ?>)</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($users)): ?>
                        <p style="padding:20px;color:var(--muted);">No hay usuarios registrados todavía.</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr><th>Nombre</th><th>Correo</th><th>Rol</th><th style="width:180px;">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($u['nombre_completo']) ?></strong></td>
                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="save_user">
                                            <input type="hidden" name="tab" value="roles">
                                            <input type="hidden" name="email" value="<?= htmlspecialchars($u['email']) ?>">
                                            <input type="hidden" name="nombre_completo" value="<?= htmlspecialchars($u['nombre_completo']) ?>">
                                            <select name="role_id" class="form-control" onchange="this.form.submit()">
                                                <?php foreach ($roles as $r): ?>
                                                    <option value="<?= (int) $r['id'] ?>" <?= (int) $u['role_id'] === (int) $r['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($r['nombre']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars($u['nombre_completo'], ENT_QUOTES) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="tab" value="roles">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══════════ TAB 2: NIVELES DOA ══════════ -->
        <div class="tab-panel" id="tab-doa">
            <div class="card" style="border-top-left-radius:0;">
                <div class="card-header"><h2>Crear / actualizar nivel (1–10)</h2></div>
                <div class="card-body">
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="save_level">
                        <input type="hidden" name="tab" value="doa">
                        <input type="number" name="nivel" class="form-control" min="1" max="10" required
                               placeholder="Nivel" style="max-width:90px;">
                        <input type="text" name="nombre" class="form-control" required
                               placeholder="Nombre del nivel" style="min-width:200px;">
                        <input type="number" name="min_amount" class="form-control" step="0.01" min="0" required
                               placeholder="Mín USD" style="max-width:130px;">
                        <input type="number" name="max_amount" class="form-control" step="0.01" min="0"
                               placeholder="Máx USD (vacío = sin límite)" style="max-width:210px;">
                        <button type="submit" class="btn btn-primary">Guardar nivel</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Niveles configurados (<?= count($levels) ?>)</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($levels)): ?>
                        <p style="padding:20px;color:var(--muted);">No hay niveles DOA configurados.</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr><th style="width:60px;">Nivel</th><th>Nombre</th><th>Rango (USD)</th><th>Usuarios asignados</th><th style="width:110px;"></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($levels as $lv): ?>
                                <tr>
                                    <td><strong><?= (int) $lv['nivel'] ?></strong></td>
                                    <td><?= htmlspecialchars($lv['nombre']) ?></td>
                                    <td>
                                        $<?= number_format((float) $lv['min_amount'], 2) ?>
                                        — <?= $lv['max_amount'] !== null ? '$' . number_format((float) $lv['max_amount'], 2) : 'Sin límite' ?>
                                    </td>
                                    <td>
                                        <?php $la = $assign_by_level[(int) $lv['id']] ?? []; ?>
                                        <?php if (empty($la)): ?>
                                            <span class="muted-sm">—</span>
                                        <?php else: ?>
                                            <ul class="doa-users">
                                                <?php foreach ($la as $a): ?>
                                                <li>
                                                    <span><?= htmlspecialchars($a['nombre_completo']) ?> <span class="muted-sm">(<?= htmlspecialchars($a['email']) ?>)</span></span>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                        <input type="hidden" name="action" value="remove_doa">
                                                        <input type="hidden" name="tab" value="doa">
                                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Quitar">✖</button>
                                                    </form>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('¿Eliminar el nivel <?= (int) $lv['nivel'] ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_level">
                                            <input type="hidden" name="tab" value="doa">
                                            <input type="hidden" name="id" value="<?= (int) $lv['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Asignar usuario a un nivel</h2></div>
                <div class="card-body">
                    <?php if (empty($levels) || empty($users)): ?>
                        <p class="muted-sm">Necesitas al menos un nivel y un usuario registrados.</p>
                    <?php else: ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="assign_doa">
                        <input type="hidden" name="tab" value="doa">
                        <select name="usuario_id" class="form-control" required style="min-width:260px;">
                            <option value="">Usuario…</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>">
                                    <?= htmlspecialchars($u['nombre_completo']) ?> — <?= htmlspecialchars($u['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="nivel_id" class="form-control" required>
                            <option value="">Nivel…</option>
                            <?php foreach ($levels as $lv): ?>
                                <option value="<?= (int) $lv['id'] ?>">Nivel <?= (int) $lv['nivel'] ?> — <?= htmlspecialchars($lv['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Asignar</button>
                    </form>
                    <p class="muted-sm" style="margin-top:8px;">Un usuario solo puede pertenecer a un nivel; reasignarlo lo mueve al nuevo nivel.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══════════ TAB 3: SUPLENCIAS ══════════ -->
        <div class="tab-panel" id="tab-backups">
            <div class="card" style="border-top-left-radius:0;">
                <div class="card-header"><h2>Registrar suplencia</h2></div>
                <div class="card-body">
                    <?php if (count($users) < 2): ?>
                        <p class="muted-sm">Necesitas al menos dos usuarios registrados.</p>
                    <?php else: ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="save_backup">
                        <input type="hidden" name="tab" value="backups">
                        <label class="muted-sm">Titular
                            <select name="titular_id" class="form-control" required style="min-width:220px;">
                                <option value="">Aprobador titular…</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nombre_completo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="muted-sm">Backup
                            <select name="backup_id" class="form-control" required style="min-width:220px;">
                                <option value="">Usuario suplente…</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nombre_completo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="muted-sm">Desde <input type="date" name="fecha_inicio" class="form-control"></label>
                        <label class="muted-sm">Hasta <input type="date" name="fecha_fin" class="form-control"></label>
                        <button type="submit" class="btn btn-primary">Registrar</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Suplencias registradas (<?= count($backups) ?>)</h2></div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($backups)): ?>
                        <p style="padding:20px;color:var(--muted);">No hay suplencias registradas.</p>
                    <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr><th>Titular</th><th>Backup</th><th>Periodo</th><th>Estado</th><th style="width:180px;"></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $b): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($b['titular_nombre']) ?></strong><br><span class="muted-sm"><?= htmlspecialchars($b['titular_email']) ?></span></td>
                                    <td><strong><?= htmlspecialchars($b['backup_nombre']) ?></strong><br><span class="muted-sm"><?= htmlspecialchars($b['backup_email']) ?></span></td>
                                    <td>
                                        <?= $b['fecha_inicio'] ? htmlspecialchars($b['fecha_inicio']) : '—' ?>
                                        → <?= $b['fecha_fin'] ? htmlspecialchars($b['fecha_fin']) : '—' ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $b['activo'] ? 'approved' : 'rejected' ?>">
                                            <?= $b['activo'] ? 'Activa' : 'Inactiva' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                <input type="hidden" name="action" value="toggle_backup">
                                                <input type="hidden" name="tab" value="backups">
                                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm"><?= $b['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('¿Eliminar esta suplencia?');">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                <input type="hidden" name="action" value="delete_backup">
                                                <input type="hidden" name="tab" value="backups">
                                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.tab-btn');
    const panels  = document.querySelectorAll('.tab-panel');
    function activate(tab) {
        buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        panels.forEach(p => p.classList.toggle('active', p.id === 'tab-' + tab));
    }
    buttons.forEach(b => b.addEventListener('click', () => activate(b.dataset.tab)));
    activate(<?= json_encode($active_tab) ?>);
})();
</script>
</body>
</html>
