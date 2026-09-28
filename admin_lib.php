<?php
/**
 * admin_lib.php — Lógica de negocio del panel administrativo:
 * roles, permisos, guard de acceso y utilidades DOA / suplencias.
 */
require_once __DIR__ . '/db.php';

const ROLE_ADMIN      = 'admin';
const ROLE_CAPTURISTA = 'capturista';
const ROLE_APROBADOR  = 'aprobador';
const ROLE_OBSERVADOR = 'observador';

/** Correo corporativo del usuario en sesión (o null). */
function admin_session_email(): ?string {
    $mail = $_SESSION['user']['mail'] ?? '';
    return $mail !== '' ? strtolower(trim($mail)) : null;
}

/** Fila de `usuarios` del usuario en sesión (o null si no está registrado). */
function admin_current_user(PDO $pdo): ?array {
    $email    = admin_session_email();
    $username = strtolower(trim($_SESSION['user']['username'] ?? ''));
    if ($email === null && $username === '') {
        return null;
    }
    // Empareja por correo exacto o, si no coincide (p. ej. el dominio de correo
    // de AD difiere), por nombre de usuario de red — así el admin sembrado
    // sigue reconociéndose aunque el atributo "mail" de LDAP no calce exacto.
    $stmt = $pdo->prepare('
        SELECT u.*, r.clave AS role_clave, r.nombre AS role_nombre
        FROM usuarios u JOIN roles r ON r.id = u.role_id
        WHERE LOWER(u.email) = :email OR (:username1 <> "" AND LOWER(u.username) = :username2)
        LIMIT 1
    ');
    $stmt->execute([':email' => $email ?? '', ':username1' => $username, ':username2' => $username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** True si aún no existe ningún administrador (modo arranque inicial). */
function admin_is_bootstrap(PDO $pdo): bool {
    $sql = "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.role_id
            WHERE r.clave = 'admin' AND u.activo = 1";
    return (int) $pdo->query($sql)->fetchColumn() === 0;
}

/** Clave del rol REAL del usuario (según BD), considerando el modo arranque. */
function admin_real_role(PDO $pdo): ?string {
    if (admin_is_bootstrap($pdo)) {
        return ROLE_ADMIN; // Cualquier usuario logueado puede crear el primer admin.
    }
    $user = admin_current_user($pdo);
    return $user['role_clave'] ?? null;
}

/** Roles que un administrador puede previsualizar ("Ver como"). */
function admin_viewable_roles(): array {
    return [ROLE_ADMIN, ROLE_CAPTURISTA, ROLE_APROBADOR, ROLE_OBSERVADOR];
}

/**
 * Rol EFECTIVO: si el usuario real es admin y activó "Ver como" otro rol,
 * devuelve ese rol de previsualización; de lo contrario, el rol real.
 */
function admin_effective_role(PDO $pdo): ?string {
    $real = admin_real_role($pdo);
    if ($real === ROLE_ADMIN) {
        $view_as = $_SESSION['view_as'] ?? '';
        if (in_array($view_as, [ROLE_CAPTURISTA, ROLE_APROBADOR, ROLE_OBSERVADOR], true)) {
            return $view_as;
        }
    }
    return $real;
}

/** True si un admin está previsualizando el sitio como otro rol. */
function admin_is_previewing(PDO $pdo): bool {
    return admin_real_role($pdo) === ROLE_ADMIN
        && admin_effective_role($pdo) !== ROLE_ADMIN;
}

/** Fija (o limpia) el rol de previsualización en la sesión. */
function admin_set_view_as(?string $role): void {
    if (!in_array($role, [ROLE_CAPTURISTA, ROLE_APROBADOR, ROLE_OBSERVADOR], true)) {
        unset($_SESSION['view_as']); // 'admin', null o inválido → sin previsualización.
    } else {
        $_SESSION['view_as'] = $role;
    }
}

/** Compatibilidad: rol vigente (efectivo) del usuario. */
function admin_current_role(PDO $pdo): ?string {
    return admin_effective_role($pdo);
}

/** Guard: detiene la ejecución si el usuario REAL no es administrador. */
function admin_require_admin(PDO $pdo): void {
    if (admin_real_role($pdo) !== ROLE_ADMIN) {
        header('Location: index.php?error=forbidden');
        exit;
    }
}

/** ¿Debe mostrarse el enlace "Admin" en el menú? (según rol efectivo). */
function admin_can_see_panel(PDO $pdo): bool {
    return admin_effective_role($pdo) === ROLE_ADMIN;
}

/** Lista de roles para selects. */
function admin_all_roles(PDO $pdo): array {
    return $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
}

/** Lista de usuarios con su rol. */
function admin_all_users(PDO $pdo): array {
    return $pdo->query('
        SELECT u.*, r.clave AS role_clave, r.nombre AS role_nombre
        FROM usuarios u JOIN roles r ON r.id = u.role_id
        ORDER BY u.nombre_completo
    ')->fetchAll();
}

/** Niveles DOA ordenados. */
function admin_all_levels(PDO $pdo): array {
    return $pdo->query('SELECT * FROM doa_niveles ORDER BY nivel')->fetchAll();
}

/** Asignaciones usuario↔nivel con datos del usuario. */
function admin_level_assignments(PDO $pdo): array {
    return $pdo->query('
        SELECT du.id, du.nivel_id, du.usuario_id,
               u.nombre_completo, u.email, r.clave AS role_clave
        FROM doa_usuarios du
        JOIN usuarios u ON u.id = du.usuario_id
        JOIN roles r    ON r.id = u.role_id
        ORDER BY du.nivel_id, u.nombre_completo
    ')->fetchAll();
}

/** Suplencias con nombres de titular y backup. */
function admin_all_backups(PDO $pdo): array {
    return $pdo->query('
        SELECT b.*,
               t.nombre_completo AS titular_nombre, t.email AS titular_email,
               s.nombre_completo AS backup_nombre,  s.email AS backup_email
        FROM usuario_backups b
        JOIN usuarios t ON t.id = b.titular_id
        JOIN usuarios s ON s.id = b.backup_id
        ORDER BY b.activo DESC, t.nombre_completo
    ')->fetchAll();
}

/**
 * Inserta o actualiza un usuario por correo y le asigna un rol.
 * Devuelve el id del usuario.
 */
function admin_upsert_user(PDO $pdo, string $email, string $nombre, int $role_id, ?string $username): int {
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('
        INSERT INTO usuarios (email, username, nombre_completo, role_id, activo)
        VALUES (:email, :username, :nombre, :role_id, 1)
        ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            nombre_completo = VALUES(nombre_completo),
            role_id = VALUES(role_id),
            activo = 1
    ');
    $stmt->execute([
        ':email'    => $email,
        ':username' => $username !== '' ? $username : null,
        ':nombre'   => $nombre,
        ':role_id'  => $role_id,
    ]);
    $stmt2 = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
    $stmt2->execute([$email]);
    return (int) $stmt2->fetchColumn();
}

/** Token CSRF de sesión (se crea si no existe). */
function admin_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Valida el token CSRF recibido por POST. */
function admin_check_csrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}
